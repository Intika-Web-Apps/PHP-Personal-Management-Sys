<?php

namespace App\Controller\Modules\Contacts;

use App\Controller\Utils\Application;
use App\Controller\Utils\Repositories;
use App\DTO\Modules\Contacts\ContactsTypesDTO;
use App\Entity\Modules\Contacts\MyContactType;
use App\Services\Exceptions\ExceptionDuplicatedTranslationKey;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MyContactsSettingsController extends AbstractController {

    const TWIG_TEMPLATE_SETTINGS             = 'modules/my-contacts/settings.html.twig';
    const TWIG_TEMPLATE_CONTACT_TYPES_TABLE  = 'modules/my-contacts/components/settings/types-settings.table.html.twig';
    const TWIG_TEMPLATE_CONTACT_GROUPS_TABLE = 'modules/my-contacts/components/settings/groups-settings.table.html.twig';

    const KEY_MESSAGE = "message";

    /**
     * @var Application
     */
    private $app;

    public function __construct(Application $app) {
        $this->app = $app;
    }

    /**
     * @Route("/my-contacts-settings", name="my-contacts-settings")
     * @param Request $request
     * @return Response
     */
    public function displaySettingsPage(Request $request) {
        $response = $this->submitContactTypeForm($request);

        if ($response->getStatusCode() != 200) {
            return $response;
        }

        $response = $this->submitContactGroupForm($request);

        if ($response->getStatusCode() != 200) {
            return $response;
        }

        if (!$request->isXmlHttpRequest()) {
            return $this->renderSettingsTemplate(false);
        }


        return $this->renderSettingsTemplate(true);
    }

    public function renderSettingsTemplate($ajax_render = false) {

        $type_form  = $this->app->forms->contactTypeForm();
        $group_form = $this->app->forms->contactGroupForm();

        $data = [
          'type_form'            => $type_form->createView(),
          'group_form'           => $group_form->createView(),
          'ajax_render'          => $ajax_render,
          'contact_types_table'  => $this->renderContactTypeTemplate()->getContent(),
          'contact_groups_table' => $this->renderContactGroupTemplate()->getContent()
        ];

        return $this->render(self::TWIG_TEMPLATE_SETTINGS, $data);
    }

    /**
     * @param bool $ajax_render
     * @return Response
     */
    public function renderContactTypeTemplate($ajax_render = false) {

        $types = $this->app->repositories->myContactTypeRepository->findBy(['deleted' => 0]);

        $data = [
            'ajax_render' => $ajax_render,
            'types'       => $types,
        ];

        return $this->render(self::TWIG_TEMPLATE_CONTACT_TYPES_TABLE, $data);
    }

    /**
     * @param bool $ajax_render
     * @return Response
     */
    public function renderContactGroupTemplate($ajax_render = false) {

        $groups = $this->app->repositories->myContactGroupRepository->findBy(['deleted' => 0]);

        $data = [
            'ajax_render' => $ajax_render,
            'groups'      => $groups,
        ];

        return $this->render(self::TWIG_TEMPLATE_CONTACT_GROUPS_TABLE, $data);
    }

    /**
     * @param Request $request
     * @return Response
     */
    private function submitContactTypeForm(Request $request):Response {
        $form = $this->app->forms->contactTypeForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            /**
             * @var MyContactType $contact_type
             */
            $contact_type = $form->getData();
            $name         = $contact_type->getName();

            if (!is_null($contact_type) && $this->app->repositories->myContactTypeRepository->findBy([ 'name' => $name ] )) {
                $record_with_this_name_exist = $this->app->translator->translate('db.recordWithThisNameExist');
                return new JsonResponse($record_with_this_name_exist, 409);
            }

            $original_image_path = $contact_type->getImagePath();
            $image_path          = FilesHandler::addTrailingSlashIfMissing($original_image_path, true);
            $contact_type->setImagePath($image_path);

            $this->app->em->persist($contact_type);
            $this->app->em->flush();
        }

        $form_submitted_message = $this->app->translator->translate('forms.general.success');
        return new JsonResponse($form_submitted_message, 200);
    }

    /**
     * @param Request $request
     * @return Response
     */
    private function submitContactGroupForm(Request $request):Response {
        $form = $this->app->forms->contactGroupForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            /**
             * @var MyContactType $form_data
             */
            $form_data = $form->getData();
            $name      = $form_data->getName();

            if (!is_null($form_data) && $this->app->repositories->myContactGroupRepository->findBy([ 'name' => $name ] )) {
                $record_with_this_name_exist = $this->app->translator->translate('db.recordWithThisNameExist');
                return new JsonResponse($record_with_this_name_exist, 409);
            }

            $this->app->em->persist($form_data);
            $this->app->em->flush();
        }

        $form_submitted_message = $this->app->translator->translate('forms.general.success');
        return new JsonResponse($form_submitted_message, 200);
    }

    /**
     * @Route("/my-contacts-types/remove", name="my-contacts-types-remove")
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function removeContactType(Request $request) {

        $record_id  = $request->request->get('id');
        $are_there_active_contacts_with_contact_type = $this->areThereActiveContactsWithContactType($record_id);

        if( $are_there_active_contacts_with_contact_type ){
            $message = $this->app->translator->translate('db.foreignKeyViolation');
            $response_data = [
                self::KEY_MESSAGE => $message,
            ];
            return new JsonResponse($response_data, 500);
        }

        $response = $this->app->repositories->deleteById(
            Repositories::MY_CONTACT_TYPE_REPOSITORY,
            $record_id
        );

        if ($response->getStatusCode() == 200) {
            return $this->renderSettingsTemplate(true);
        }
        return $response;
    }

    /**
     * This function checks if there are any contacts with deleted = 0 that still use this contact type
     * Jsons are not cleared for removal - with this minimal data that there is, it's possible to revert the contact
     * @param string $record_id
     * @return bool
     */
    private function areThereActiveContactsWithContactType(string $record_id):bool {
        $removed_record     = $this->app->repositories->myContactTypeRepository->find($record_id);
        $contact_type_name  = $removed_record->getName();
        $contacts           = $this->app->repositories->myContactRepository->findContactsWithContactTypeByContactTypeName($contact_type_name);

        return !empty($contacts);
    }

    /**
     * @Route("/my-contacts-types/update",name="my-contacts-types-update")
     * @param Request $request
     * @return Response
     * @throws ExceptionDuplicatedTranslationKey
     * @throws Exception
     */
    public function updateContactType(Request $request) {
        $parameters = $request->request->all();

        $entity_after_update  = $this->app->repositories->myContactTypeRepository->find($parameters['id']);
        $entity_before_update = clone($entity_after_update); // because doctrine will overwrite the data we got to clone it

        $this->app->em->beginTransaction(); //all or nothing
        {
            $response = $this->app->repositories->update($parameters, $entity_after_update);

            try{
                $this->updateContactsForUpdatedType($entity_before_update, $entity_after_update);
            }catch (Exception $e){
                $response = new Response("Could not update the contacts for updated contact type");
            }

        }
        $this->app->em->commit();

        return $response;
    }

    /**
     * @param MyContactType $entity_before_update
     * @param MyContactType $entity_after_update
     * @throws Exception
     */
    private function updateContactsForUpdatedType(MyContactType $entity_before_update, MyContactType $entity_after_update)
    {
        $previous_contact_type_name = $entity_before_update->getName();

        $new_contact_type_name       = $entity_after_update->getName();
        $new_contact_type_image_path = $entity_after_update->getImagePath();

        $contacts_to_update = $this->app->repositories->myContactRepository->findContactsWithContactTypeByContactTypeName($previous_contact_type_name);

        foreach($contacts_to_update as $contact_to_update)
        {
            $contacts_types_dtos = $contact_to_update->getContacts()->getContactTypeDtos();

            foreach($contacts_types_dtos as $index => $contact_type_dto){
                if( strtolower($contact_type_dto->getName()) === strtolower($previous_contact_type_name) )
                {
                    $contact_type_dto->setName($new_contact_type_name);
                    $contact_type_dto->setIconPath($new_contact_type_image_path);
                    $contacts_types_dtos[$index] = $contact_type_dto;
                }
            }

            $contacts_types_dto = new ContactsTypesDTO();
            $contacts_types_dto->setContactTypeDtos($contacts_types_dtos);

            $json = $contacts_types_dto->toJson();

            $contact_to_update->setContacts($json);
            $this->app->repositories->myContactRepository->saveEntity($contact_to_update);
        }

    }

    /**
     * @Route("/my-contacts-groups/remove", name="my-contacts-groups-remove")
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function removeContactGroup(Request $request) {

        $response = $this->app->repositories->deleteById(
            Repositories::MY_CONTACT_GROUP_REPOSITORY,
            $request->request->get('id')
        );

        if ($response->getStatusCode() == 200) {
            return $this->renderSettingsTemplate(true);
        }
        return $response;
    }

    /**
     * @Route("/my-contacts-groups/update",name="my-contacts-groups-update")
     * @param Request $request
     * @return Response
     * @throws ExceptionDuplicatedTranslationKey
     */
    public function updateContactGroup(Request $request) {
        $parameters = $request->request->all();
        $entity     = $this->app->repositories->myContactGroupRepository->find($parameters['id']);
        $response   = $this->app->repositories->update($parameters, $entity);

        return $response;
    }
}