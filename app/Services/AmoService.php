<?php

declare(strict_types=1);

namespace App\Services;

use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\Collections\CatalogElementsCollection;
use AmoCRM\Collections\ContactsCollection;
use AmoCRM\Collections\CustomFieldsValuesCollection;
use AmoCRM\Collections\Leads\LeadsCollection;
use AmoCRM\Collections\LinksCollection;
use AmoCRM\Collections\NotesCollection;
use AmoCRM\Collections\TasksCollection;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use League\OAuth2\Client\Token\AccessToken;
use PhpOption\None;

class AmoService
{
    public AmoCRMApiClient $Api;

    private const PRODUCT_NAME_FIELD_ID = 465113;
    private const MARGINALITY_FIELD_ID = 465129;
    private const DATE_OF_BIRTH_FIELD_ID = 626367;
    private const GENDER_FIELD_ID = 626533;

    private const CATALOG_ID = 7527;
    private const CATALOG_PRICE_FIELD_ID = 938161;


    public function __construct()
    {
        $this->Api = $this->ConnectAmoApi();
        $jsonToken = json_decode(file_get_contents('../token.json'), true);

        $token = new AccessToken($jsonToken);

        $this->Api->setAccessToken($token);
    }

    /**
     * @return AmoCRMApiClient
     */
    public function ConnectAmoApi(): AmoCRMApiClient
    {
        $api = new AmoCRMApiClient(
            Config::get('app.amo_api.client_id'),
            Config::get('app.amo_api.client_secret'),
            Config::get('app.amo_api.client_redirect_uri')
        );

        return $api->setAccountBaseDomain(Config::get('app.amo_api.account_domain'));
    }

    /**
     * @param string $name
     * @param int $price
     * @return LeadModel
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMoAuthApiException
     */
    public function CreateLead(string $name, int $price): LeadModel
    {
        // Расчёт маржинальности
        $marginality = $price / 2;

        // Создание Сделки
        $lead = (new LeadModel())->setName("Сделка <<{$name}>>")
            ->setPrice($price)->setCustomFieldsValues(
                (new CustomFieldsValuesCollection())->add(
                    (new TextCustomFieldValuesModel())->setFieldId(
                        self::PRODUCT_NAME_FIELD_ID
                    )->setValues(
                        (new TextCustomFieldValueCollection())->add(
                            (new TextCustomFieldValueModel())->setValue(
                                $name
                            )
                        )
                    )
                )->add(
                    (new NumericCustomFieldValuesModel())->setFieldId(
                        self::MARGINALITY_FIELD_ID
                    )->setValues(
                        (new NumericCustomFieldValueCollection())->add(
                            (new NumericCustomFieldValueModel())->setValue(
                                $marginality
                            )
                        )
                    )
                )
            );

        // Запрос на создание сделки
        return $this->Api->leads()->addOne($lead);
    }

    /**
     * @param LeadModel $lead
     * @param UserModel $user
     * @return TasksCollection
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMoAuthApiException
     */
    public function CreateTask(LeadModel $lead, UserModel $user): TasksCollection
    {
        // Создадим задачу
        $tasksCollection = new TasksCollection();

        $task = new TaskModel();
        $task->setTaskTypeId(TaskModel::TASK_TYPE_ID_FOLLOW_UP)
            ->setText("{$lead->getName()}")
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

        return $this->Api->tasks()->add($tasksCollection);
    }

    /**
     * @return UserModel
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMoAuthApiException
     */
    public function getRandomUser(): UserModel
    {
        return (collect($this->Api->users()->get())->random());
    }

    /**
     * @throws AmoCRMApiException
     * @throws AmoCRMoAuthApiException
     * @throws AmoCRMMissedTokenException
     */
    public function getContactWherePhone(string $phoneNumber): ContactModel|null
    {
        $contacts = $this->getContactsWithLeads();
        return ($contacts == null) ? null : $this->searchContact($contacts, $phoneNumber);
    }

    /**
     * @throws AmoCRMoAuthApiException
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     */
    public function getContactsWithLeads(): ContactsCollection|null
    {
        try {
            $contacts = $this->Api->contacts()->get(with: [ContactModel::LEADS]);
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
        if ($leads != null) {
            foreach ($leads as $lead) {
                $lead = $this->Api->leads()->getOne($lead->getId());
                if ($lead->getStatusId() == LeadModel::WON_STATUS_ID) {
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
    private function searchContact(ContactsCollection $contacts, string $phone): ContactModel|null
    {
        foreach ($contacts as $contact) {
            $contactsPhoneNumbers = $contact->getCustomFieldsValues()
                ->getBy('fieldCode', 'PHONE')
                ->getValues();
            foreach ($contactsPhoneNumbers as $phoneNumber) {
                if ($phoneNumber->getValue() == $phone) {
                    return $contact;
                }
            }
        }

        return null;
    }

    /**
     * @param LeadModel $lead
     * @param CatalogModel $productsCatalog
     * @param CatalogElementsCollection $products
     * @return void
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMoAuthApiException
     * @throws InvalidArgumentException
     */
    public function linkCatalogToLead(
        LeadModel $lead,
        CatalogModel $productsCatalog,
        CatalogElementsCollection $products
    ): void {
        $lead_links = new LinksCollection();

        $elements = $this->Api->catalogElements($productsCatalog->getId())
            ->add($products);

        foreach ($elements as $element) {
            $lead_links->add(
                $element->setQuantity(rand(1_000, 900_000))
            );
        }

        $this->Api->leads()->link($lead, $lead_links);
    }

    public function linkContactToLead(LeadModel $lead, ContactModel $contact): void
    {
        $contact = $this->Api->contacts()->addOne($contact);
        $contact_links = new LinksCollection();
        $contact_links->add($lead);
        $this->Api->contacts()->link($contact, $contact_links);
    }

    /**
     * @param array $data
     * @return ContactModel
     */
    public function CreateContact(
        array $data = [
            'lastName' => '',
            'firstname' => '',
            'randomUserId' => '',
            'phoneNumber' => '',
            'email' => '',
            'dateOfBirth' => '',
            'gender' => '',
        ]
    ): ContactModel {
        [
            $lastName,
            $firstname,
            $randomUserId,
            $phoneNumber,
            $email,
            $dateOfBirth,
            $gender
        ] = $data;

        // Создание Контакта
        $contact = new ContactModel();
        $contact->setName("{$lastName} {$firstname}");
        $contact->setFirstName($firstname);
        $contact->setLastName($lastName);
        $contact->setResponsibleUserId($randomUserId);

        $contactCustomFields = (new CustomFieldsValuesCollection())
            ->add(
                (new MultitextCustomFieldValuesModel())
                    ->setFieldCode('PHONE')
                    ->setValues(
                        (new MultitextCustomFieldValueCollection())
                            ->add(
                                (new MultitextCustomFieldValueModel())
                                    ->setEnum('WORK')
                                    ->setValue($phoneNumber)
                            )
                    )
            )
            ->add(
                (new MultitextCustomFieldValuesModel())
                    ->setFieldCode('EMAIL')
                    ->setValues(
                        (new MultitextCustomFieldValueCollection())
                            ->add(
                                (new MultitextCustomFieldValueModel())
                                    ->setEnum('WORK')
                                    ->setValue($email)
                            )
                    )
            )
            ->add(
                (new TextCustomFieldValuesModel())
                    ->setFieldId(self::DATE_OF_BIRTH_FIELD_ID)
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
                    ->setFieldId(self::GENDER_FIELD_ID)
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
    public function CreateProducts(): array
    {
        // Products
        $catalogs = $this->Api->catalogs()->get();
        $productsCatalog = $catalogs->getBy('id', self::CATALOG_ID);

        $products = (new CatalogElementsCollection())
            ->add(
                (new CatalogElementModel())
                    ->setName('Ayaneo 5')
                    ->setCustomFieldsValues(
                        (new CustomFieldsValuesCollection())
                            ->add(
                                (new NumericCustomFieldValuesModel())
                                    ->setFieldId(
                                        self::CATALOG_PRICE_FIELD_ID
                                    )
                                    ->setValues(
                                        (new NumericCustomFieldValueCollection())
                                            ->add(
                                                (new NumericCustomFieldValueModel())
                                                    ->setValue(
                                                        50_000
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
                                        self::CATALOG_PRICE_FIELD_ID
                                    )
                                    ->setValues(
                                        (new NumericCustomFieldValueCollection())
                                            ->add(
                                                (new NumericCustomFieldValueModel())
                                                    ->setValue(
                                                        70_000
                                                    )
                                            )
                                    )
                            )
                    )
            );

        return [$productsCatalog, $products];
    }

    public function CreateNote(
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
        $leadNotesService = $this->Api->notes(EntityTypesInterface::CONTACTS);
        $notesCollection = $leadNotesService->add($notesCollection);
    }
}
