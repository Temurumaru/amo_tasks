<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
use AmoCRM\Models\ContactModel;
use AmoCRM\Models\Customers\CustomerModel;
use AmoCRM\Models\CustomFieldsValues\MultitextCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\NumericCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\SelectCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\TextCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\MultitextCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\NumericCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\SelectCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\TextCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueModels\MultitextCustomFieldValueModel;
use AmoCRM\Models\CustomFieldsValues\ValueModels\NumericCustomFieldValueModel;
use AmoCRM\Models\CustomFieldsValues\ValueModels\SelectCustomFieldValueModel;
use AmoCRM\Models\CustomFieldsValues\ValueModels\TextCustomFieldValueModel;
use AmoCRM\Models\LeadModel;
use AmoCRM\Models\NoteType\ServiceMessageNote;
use AmoCRM\Models\TaskModel;
use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use League\OAuth2\Client\Token\AccessToken;

use App\Services\AmoService;


class MainController extends Controller
{

    /**
     * @throws AmoCRMoAuthApiException
     */
    public function credentials(Request $req): \Illuminate\Http\JsonResponse
    {
        $api = (new AmoService())->ConnectAmoApi();

        $token = $api->getOAuthClient()->getAccessTokenByCode($req->code);

        file_put_contents('../token.json', json_encode($token->jsonSerialize(), JSON_PRETTY_PRINT));

        return response()->json(
            [
                'success' => 'Successful completed'
            ]
        );
    }


    /**
     * @throws AmoCRMoAuthApiException
     * @throws InvalidArgumentException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMApiException
     */
    public function leadCreate(Request $req): \Illuminate\Http\JsonResponse
    {
        $req->validate([
            'product_name' => 'required|string',
            'product_price' => 'required',
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'date_of_birth' => 'required',
            'gender' => 'required',
            'phone_number' => 'required',
            'email' => 'required|email',
        ]);

        $amo = new AmoService();

        $productName = $req->product_name;
        $leadName = $productName;
        $leadPrice = (int)$req->product_price;

        // Рандомный Пользователь
        $randomUser = $amo->getRandomUser();
        $randomUserId = $randomUser->getId();

        // Получение искомого Контакта
        $contact = $amo->getContactWherePhone($req->phone_number);


        if ($contact !== null) {

            // Проверяем на 142 статус (то есть что Сделка завершена)
            if ($amo->isLeadSucceeded($contact->getLeads())) {
                // Создаём покупателя
                $customer = (new CustomerModel())->setName("Test Customer")->setNextDate(time());
                try {
                    $customer = $amo->Api->customers()->addOne($customer);
                } catch (AmoCRMApiException $e) {
                    dd($e);
                }
                $links = (new LinksCollection())->add($contact);
                $amo->Api->customers()->link($customer, $links);
                $amo->CreateNote($contact, "Создан покупатель {$customer->getName()}", $randomUserId);

                return response()->json(
                    [
                        'success' => 'Добавлен покупатель'
                    ]
                );
            } else {
                //Создадим примечания
                $amo->CreateNote($contact, "Совершена попытка создать дубль {$contact->getLastName()} {$contact->getFirstName()}", $randomUserId);

                return response()->json(
                    [
                        'success' => 'Добавлена заметка в контакт'
                    ]
                );
            }
        }

        if (empty($contact)) {
            // Создание Контакта
            $contact = $amo->CreateContact([
                $req->last_name,
                $req->first_name,
                $randomUserId,
                $req->phone_number,
                $req->email,
                $req->date_of_birth,
                $req->gender
            ]);

            // Создание Сделки
            $lead = $amo->CreateLead($leadName, $leadPrice);

            // Создадим задачу
            $amo->CreateTask($lead, $randomUser);

            // Products
            [$productsCatalog, $products] = $amo->CreateProducts();

            $amo->linkCatalogToLead($lead, $productsCatalog, $products);
            $amo->linkContactToLead($lead, $contact);
        }

        return response()->json(
            [
                'success' => 'Сделка успешно создана'
            ]
        );
    }
}
