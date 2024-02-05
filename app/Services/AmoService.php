<?php

declare(strict_types=1);

namespace App\Services;

use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\Collections\CatalogElementsCollection;
use AmoCRM\Collections\CatalogsCollection;
use AmoCRM\Collections\ContactsCollection;
use AmoCRM\Collections\CustomFieldsValuesCollection;
use AmoCRM\Collections\Leads\LeadsCollection;
use AmoCRM\Collections\LinksCollection;
use AmoCRM\Collections\NotesCollection;
use AmoCRM\Collections\TasksCollection;
use AmoCRM\EntitiesServices\CatalogElements;
use AmoCRM\Exceptions\AmoCRMApiException;
use AmoCRM\Exceptions\AmoCRMMissedTokenException;
use AmoCRM\Exceptions\AmoCRMoAuthApiException;
use AmoCRM\Exceptions\InvalidArgumentException;
use AmoCRM\Helpers\EntityTypesInterface;
use AmoCRM\Models\CatalogElementModel;
use AmoCRM\Models\CatalogModel;
use AmoCRM\Models\ContactModel;
use AmoCRM\Models\CustomFieldsValues\MultitextCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\NumericCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\TextCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\MultitextCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\NumericCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\TextCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueModels\MultitextCustomFieldValueModel;
use AmoCRM\Models\CustomFieldsValues\ValueModels\NumericCustomFieldValueModel;
use AmoCRM\Models\CustomFieldsValues\ValueModels\TextCustomFieldValueModel;
use AmoCRM\Models\LeadModel;
use AmoCRM\Models\NoteType\ServiceMessageNote;
use AmoCRM\Models\TaskModel;
use AmoCRM\Models\UserModel;
use Illuminate\Support\Facades\Config;
use League\OAuth2\Client\Token\AccessToken;
use App\Enums\FieldCodesEnum;

class AmoService
{
    public AmoCRMApiClient $api;

    public function __construct()
    {
        $this->api = $this->connectAmoApi();
        $jsonToken = json_decode(file_get_contents('../token.json'), true);

        $token = new AccessToken($jsonToken);

        $this->api->setAccessToken($token);
    }

    /**
     * @return AmoCRMApiClient
     */
    public function connectAmoApi(): AmoCRMApiClient
    {
        $api = new AmoCRMApiClient(
            Config::get('app.amo_api.client_id'),
            Config::get('app.amo_api.client_secret'),
            Config::get('app.amo_api.client_redirect_uri')
        );

        return $api->setAccountBaseDomain(Config::get('app.amo_api.account_domain'));
    }

    /**
     * @param string $leadName
     * @param string $productName
     * @param int $price
     * @param int $randomUserId
     * @return LeadModel
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMoAuthApiException
     */
    public function createLead(string $leadName, string $productName, int $price, int $randomUserId): LeadModel
    {
        // Создание Сделки
        $lead = (new LeadModel())->setName($leadName)
            ->setPrice($price)
            ->setCustomFieldsValues(
                (new CustomFieldsValuesCollection())->add(
                    (new TextCustomFieldValuesModel())->setFieldId(
                        FieldCodesEnum::LEAD_PRODUCT_NAME_FIELD_ID
                    )->setValues(
                        (new TextCustomFieldValueCollection())->add(
                            (new TextCustomFieldValueModel())->setValue(
                                $productName
                            )
                        )
                    )
                )
            );

        $lead->setResponsibleUserId($randomUserId);

        // Запрос на создание сделки
        return $this->api->leads()->addOne($lead);
    }

    /**
     * @param LeadModel $lead
     * @param UserModel $user
     * @return TasksCollection
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMoAuthApiException
     */
    public function createTask(LeadModel $lead, UserModel $user): TasksCollection
    {
        // Создадим задачу
        $tasksCollection = new TasksCollection();

        $task = new TaskModel();
        $task->setTaskTypeId(TaskModel::TASK_TYPE_ID_FOLLOW_UP)
            ->setText($lead->getName())
            ->setEntityType(EntityTypesInterface::LEADS)
            ->setEntityId($lead->getId())
            ->setDuration(9 * 60 * 60) // 9 часов
            ->setResponsibleUserId($user->getId());

        // Установим срок выполнения на 4 рабочих дня с началом в 9 утра
        $workDaysToAdd = 4;
        $startDate = mktime(4, 0, 0, (int)date('m'), (int)date('d'), (int)date('Y'));
        $endDate = $startDate;

        while ($workDaysToAdd > 0) {
            // Проверим, является ли текущий день выходным (субботой или воскресеньем)
            $currentDayOfWeek = date('N', $endDate);

            // Если текущий день не выходной, уменьшим счетчик рабочих дней
            if ($currentDayOfWeek <= 5) {
                $workDaysToAdd--;
            }

            // Увеличим дату на 1 день
            $endDate = strtotime('+1 day', $endDate);
        }

        $task->setCompleteTill($endDate);
        $tasksCollection->add($task);

        return $this->api->tasks()->add($tasksCollection);
    }

    /**
     * @return UserModel
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMoAuthApiException
     */
    public function getRandomUser(): UserModel
    {
        return (collect($this->api->users()->get())->random());
    }

    /**
     * @throws AmoCRMApiException
     * @throws AmoCRMoAuthApiException
     * @throws AmoCRMMissedTokenException
     */
    public function getContactWherePhone(string $phoneNumber): ContactModel|null
    {
        $contacts = $this->getContactsWithLeads();
        return $contacts === null ? null : $this->searchContactByPhone($contacts, $phoneNumber);
    }

    /**
     * @throws AmoCRMoAuthApiException
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     */
    public function getContactsWithLeads(): ContactsCollection|null
    {
        try {
            $contacts = $this->api->contacts()->get(with: [ContactModel::LEADS]);
        } catch (AmoCRMApiException $e) {
            return null;
        }

        return $contacts;
    }

    /**
     * @param LeadsCollection|null $leads
     * @return bool
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMoAuthApiException
     */
    public function isLeadSucceeded(?LeadsCollection $leads): bool
    {
        if ($leads !== null) {
            /** @var LeadModel $lead */
            foreach ($leads as $lead) {
                $lead = $this->api->leads()->getOne($lead->getId());
                if ($lead->getStatusId() === LeadModel::WON_STATUS_ID) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param ContactsCollection $contacts
     * @param string $phone
     * @return ContactModel|null
     */
    private function searchContactByPhone(ContactsCollection $contacts, string $phone): ?ContactModel
    {
        /** @var ContactModel $contact */
        foreach ($contacts as $contact) {
            $contactsPhoneNumbers = $contact->getCustomFieldsValues()
                ->getBy('fieldCode', FieldCodesEnum::PHONE_CUSTOM_FIELD_CODE)
                ->getValues();
            foreach ($contactsPhoneNumbers as $phoneNumber) {
                if ($phoneNumber->getValue() === $phone) {
                    return $contact;
                }
            }
        }

        return null;
    }

    /**
     * @param LeadModel $lead
     * @param CatalogElementsCollection $products
     * @return void
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMoAuthApiException
     * @throws InvalidArgumentException
     */
    public function linkCatalogToLead(
        LeadModel $lead,
        CatalogElementsCollection $products
    ): void {
        $leadLinks = new LinksCollection();

        /** @var CatalogElements $elements */
        $elements = $this->api->catalogElements(FieldCodesEnum::PRODUCT_CATALOG_ID)
            ->add($products);

        $this->api->leads()->link($lead, $leadLinks);
    }

    public function linkSaveContactAndContactToLead(LeadModel $lead, ContactModel $contact): void
    {
        $contact = $this->api->contacts()->addOne($contact);
        $contactLinks = new LinksCollection();
        $contactLinks->add($lead);
        $this->api->contacts()->link($contact, $contactLinks);
    }

    /**
     * @param string $lastName
     * @param string $firstname
     * @param int $randomUserId
     * @param string $phoneNumber
     * @param string $email
     * @param string $dateOfBirth
     * @param string $gender
     * @return ContactModel
     */
    public function makeContactModel(
        string $lastName,
        string $firstname,
        int $randomUserId,
        string $phoneNumber,
        string $email,
        string $dateOfBirth,
        string $gender
    ): ContactModel {
        // Создание Контакта
        $contact = new ContactModel();
        $contact->setName($lastName . ' ' . $firstname);
        $contact->setFirstName($firstname);
        $contact->setLastName($lastName);
        $contact->setResponsibleUserId($randomUserId);

        $contactCustomFields = (new CustomFieldsValuesCollection())
            ->add(
                (new MultitextCustomFieldValuesModel())
                    ->setFieldCode(FieldCodesEnum::PHONE_CUSTOM_FIELD_CODE)
                    ->setValues(
                        (new MultitextCustomFieldValueCollection())
                            ->add(
                                (new MultitextCustomFieldValueModel())
                                    ->setEnum(FieldCodesEnum::WORK_CUSTOM_FIELD_VALUE_CODE)
                                    ->setValue($phoneNumber)
                            )
                    )
            )
            ->add(
                (new MultitextCustomFieldValuesModel())
                    ->setFieldCode(FieldCodesEnum::EMAIL_CUSTOM_FIELD_CODE)
                    ->setValues(
                        (new MultitextCustomFieldValueCollection())
                            ->add(
                                (new MultitextCustomFieldValueModel())
                                    ->setEnum(FieldCodesEnum::WORK_CUSTOM_FIELD_VALUE_CODE)
                                    ->setValue($email)
                            )
                    )
            )
            ->add(
                (new TextCustomFieldValuesModel())
                    ->setFieldId(FieldCodesEnum::CONTACT_DATE_OF_BIRTH_FIELD_ID)
                    ->setValues(
                        (new TextCustomFieldValueCollection())
                            ->add(
                                (new TextCustomFieldValueModel())
                                    ->setValue($dateOfBirth)
                            )
                    )
            )
            ->add(
                (new TextCustomFieldValuesModel())
                    ->setFieldId(FieldCodesEnum::CONTACT_GENDER_FIELD_ID)
                    ->setValues(
                        (new TextCustomFieldValueCollection())
                            ->add(
                                (new TextCustomFieldValueModel())
                                    ->setValue($gender)
                            )
                    )
            );

        $contact->setCustomFieldsValues($contactCustomFields);

        return $contact;
    }

    /**
     * @return array [CatalogModel, CatalogElementsCollection]
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMoAuthApiException
     */
    public function createProducts(): CatalogElementsCollection
    {
        return (new CatalogElementsCollection())
            ->add(
                (new CatalogElementModel())
                    ->setName('Ayaneo 5')
                    ->setCustomFieldsValues(
                        (new CustomFieldsValuesCollection())
                            ->add(
                                (new NumericCustomFieldValuesModel())
                                    ->setFieldId(
                                        FieldCodesEnum::PRODUCT_CATALOG_PRICE_FIELD_ID
                                    )
                                    ->setValues(
                                        (new NumericCustomFieldValueCollection())
                                            ->add(
                                                (new NumericCustomFieldValueModel())
                                                    ->setValue(
                                                        rand(1_000, 900_000)
                                                    )
                                            )
                                    )
                            )
                    )
            )
            ->add(
                (new CatalogElementModel())
                    ->setName('Cerebras 2')
                    ->setCustomFieldsValues(
                        (new CustomFieldsValuesCollection())
                            ->add(
                                (new NumericCustomFieldValuesModel())
                                    ->setFieldId(
                                        FieldCodesEnum::PRODUCT_CATALOG_PRICE_FIELD_ID
                                    )
                                    ->setValues(
                                        (new NumericCustomFieldValueCollection())
                                            ->add(
                                                (new NumericCustomFieldValueModel())
                                                    ->setValue(
                                                        rand(1_000, 900_000)
                                                    )
                                            )
                                    )
                            )
                    )
            );
    }

    /**
     * @param ContactModel $contact
     * @param string $text
     * @param int $randomUserId
     * @return void
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMoAuthApiException
     * @throws InvalidArgumentException
     */
    public function createNote(
        ContactModel $contact,
        string $text,
        int $randomUserId
    ): void {
        //Создадим примечания
        $notesCollection = new NotesCollection();
        $serviceMessageNote = new ServiceMessageNote();
        $serviceMessageNote->setEntityId($contact->getId())
            ->setText(
                $text
            )
            ->setService('Amo Tasks')
            ->setCreatedBy($randomUserId);

        $notesCollection->add($serviceMessageNote);
        $leadNotesService = $this->api->notes(EntityTypesInterface::CONTACTS);
        $notesCollection = $leadNotesService->add($notesCollection);
    }
}
