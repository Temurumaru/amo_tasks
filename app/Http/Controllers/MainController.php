<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use AmoCRM\Collections\LinksCollection;
use AmoCRM\Exceptions\AmoCRMApiException;
use AmoCRM\Exceptions\AmoCRMMissedTokenException;
use AmoCRM\Exceptions\AmoCRMoAuthApiException;
use AmoCRM\Exceptions\InvalidArgumentException;
use AmoCRM\Models\Customers\CustomerModel;
use Illuminate\Http\Request;

use App\Services\AmoService;

class MainController extends Controller
{
    /**
     * @param Request $req
     * @return \Illuminate\Http\JsonResponse
     * @throws AmoCRMoAuthApiException
     */
    public function credentials(Request $req): \Illuminate\Http\JsonResponse
    {
        $api = (new AmoService())->connectAmoApi();

        $token = $api->getOAuthClient()->getAccessTokenByCode($req->code);

        file_put_contents('../token.json', json_encode($token->jsonSerialize(), JSON_PRETTY_PRINT));

        return response()->json(
            [
                'success' => 'Successful completed',
            ]
        );
    }


    /**
     * @param Request $req
     * @return \Illuminate\Http\JsonResponse
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMoAuthApiException
     * @throws InvalidArgumentException
     */
    public function leadRequest(Request $req): \Illuminate\Http\JsonResponse
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
        $leadName = "Сделка по покупке <<{$productName}>>";
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
                $customer = (new CustomerModel())->setName('Test Customer')->setNextDate(time());
                $customer = $amo->api->customers()->addOne($customer);
                $links = (new LinksCollection())->add($contact);
                $amo->api->customers()->link($customer, $links);
                $amo->createNote($contact, 'Создан покупатель ' . $customer->getName(), $randomUserId);

                return response()->json(
                    [
                        'success' => 'Добавлен покупатель',
                    ]
                );
            } else {
                //Создадим примечания
                $amo->createNote(
                    $contact,
                    'Совершена попытка создать дубль ' . $contact->getLastName() . ' ' . $contact->getFirstName(),
                    $randomUserId
                );

                return response()->json(
                    [
                        'success' => 'Добавлена заметка в контакт',
                    ]
                );
            }
        }

        // Создание Контакта
        $contact = $amo->toCollectContact(
            $req->last_name,
            $req->first_name,
            $randomUserId,
            $req->phone_number,
            $req->email,
            $req->date_of_birth,
            $req->gender
        );

        // Создание Сделки
        $lead = $amo->createLead($leadName, $productName, $leadPrice, $randomUserId);

        // Создадим задачу
        $amo->createTask($lead, $randomUser);

        // Products
        $products = $amo->createProducts();

        $amo->linkCatalogToLead($lead, $products);
        $amo->linkContactToLeadAndSaveContact($lead, $contact);

        return response()->json(
            [
                'success' => 'Сделка успешно создана',
            ]
        );
    }
}
