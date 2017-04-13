<?php

namespace BohSchu\Exact;

use Carbon\Carbon;
use GuzzleHttp\Client;
use BohSchu\Exact\ExactHelperTrait;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Exception\ClientException;

class ExactApi
{
    use ExactHelperTrait;

    /**
     * Guzzle Client
     *
     * @var $client
     */
    private $client;

    /**
     * Exact ERP Division Number
     *
     * @var $division
     */
    private $division;

    /**
     * Instanciate the guzzle http client and set division code
     */
    public function __construct()
    {
        $this->division = env('DIVISION_CODE');
        $this->client = new Client(['base_uri' => config('exact.base_uri')]);
    }

    /**
     * Get all purchase orders by its supplier code (Supplier Orders)
     *
     * @param $supplierCode
     * @param $select
     * @return Array
     */
    public function getPurchaseOrdersBySupplier($supplierCode, $select)
    {
        $this->checkToken();

        $uri = '/api/v1/'. $this->division
            .'/purchaseorder/PurchaseOrders?'
            . '$filter=trim(SupplierCode) eq ' . "'" . $supplierCode . "'"
            . ' and startswith(tolower(Description), '. "'moedel'" .') eq true and DropShipment eq false'
            . '&$expand=PurchaseOrderLines'
            . '&$select=' . $select;

        return $this->get($uri)->d->results;
    }

    /**
     * Get all goods deliveries by its shipping method
     *
     * @param $shippingMethod
     * @return Collection
     */
    public function getGoodsDeliveries($shippingMethod)
    {
        if ($this->checkToken() == false) {
            return false;
        }

        $uri = '/api/v1/'. $this->division .'/salesorder/GoodsDeliveries'
            . '?$filter=trim(ShippingMethodCode) eq ' . "'" . $shippingMethod . "'"
            . ' and substringof(' . "'Gedruckt'" . ',Remarks) eq false'
            . ' or Remarks eq null and trim(ShippingMethodCode) eq ' . "'" . $shippingMethod . "'"
            . '&$select=EntryID,DeliveryAccountName, DeliveryAddress,DeliveryContact,Description,DeliveryNumber,ShippingMethodCode,Remarks';

        $results = $this->get($uri)->d->results;

        return collect($results)->map(function($delivery) {
            $contact = $this->getContact($delivery->DeliveryContact, 'Email,Phone');

            $delivery->address = $this->getAdress(
                $delivery->DeliveryAddress,
                'AccountName,AddressLine1,AddressLine2,AddressLine3,City,ContactName,Country,Postcode'
            );

            $delivery->Email = $contact[0]->Email ?? '';
            $delivery->Phone = $contact[0]->Phone ?? '';

            return $delivery;
        });
    }

    /**
     * Update a goods delivery by its id and given data
     *
     * @param $id
     * @param $data
     * @return Array
     */
    public function updateGoodsDeliveries($id, $data)
    {
        $this->checkToken();

        $uri = '/api/v1/'. $this->division
            .'/salesorder/GoodsDeliveries(guid' . "'" . $id . "'" . ')';

        return $this->put($uri, $data);
    }

    /**
     * Create a new quotation
     *
     * @param $quotation
     * @return Array
     */
    public function createQuotation($quotation)
    {
        $this->checkToken();

        $account = $this->getAccountId($quotation->company, false)
                ?? $this->createAccount($quotation->company, false);

        $contact = $this->getContactId($quotation->user, $account)
                ?? $this->createContact($quotation->user, $account);

        $address = $this->getAddressId($quotation->delivery, $account)
                ?? $this->createAddress($quotation->delivery, $account);

        $quotationLines = $this->getItemIds(
            $quotation->details,
            $quotation->company->language->code,
            $quotation->delivery->language->code
        );

        $data = [
            'OrderAccount' => $account,
            'OrderAccountContact' => $contact,
            'DeliveryAddress' => $address,
            'Description' => 'Angebotsanfrage ' . Carbon::now()->format('d.m.Y'),
            'QuotationLines' => $quotationLines
        ];

        return $this->post('/api/v1/'. $this->division .'/crm/Quotations', $data);
    }

    /**
     * Create a new sales order (Customer Order)
     *
     * @param $order
     * @return Array
     */
    public function createSalesOrder($order)
    {
        $this->checkToken();

        $account = $this->getAccountId($order->company, $order->digital_bill)
                ?? $this->createAccount($order->company, $order->digital_bill);

        $contact = $this->getContactId($order->user, $account)
                ?? $this->createContact($order->user, $account);

        $address = $this->getAddressId($order->delivery, $account)
                ?? $this->createAddress($order->delivery, $account);

        $paymentCondition = $this->getPaymentCondition($order->payment_method);

        $salesOrderLines = $this->getItemIds(
            $order->details,
            $order->company->language->code,
            $order->delivery->language->code
        );

        if ($order->delivery_costs != '0.00' || $order->delivery_costs != '') {
            dump($order->delivery_costs);
            dd('lieferkosten');
            $salesOrderLines[] = $this->getDeliveryCosts(
                $order->delivery_costs,
                $order->company->language->code,
                $order->delivery->language->code
            );
        }

        $data = [
            'OrderDate' => $order->created_at->format('d.m.Y H:i:s'),
            'OrderedBy' => $account,
            'OrderedByContactPerson' => $contact,
            'DeliveryAddress' => $address,
            'YourRef' => $order->id,
            'Remarks' => $order->comments,
            'PaymentCondition' => $paymentCondition,
            'PaymentReference' => $order->digital_bill ? 'eRg.' : '',
            'SalesOrderLines' => $salesOrderLines
        ];

        return $this->post('/api/v1/'. $this->division .'/salesorder/SalesOrders', $data)->d->OrderNumber;
    }

    /**
     * Create a new account (Customer)
     *
     * @param  $account
     * @param  $gititalBill
     * @return String
     */
    public function createAccount($account, $digitalBill = false)
    {
        $this->checkToken();

        $accounting = $this->getAccountingCodes($account->language->code);

        $data = [
            'Code' => strlen($account->id) == 5 ? '10' . (string) $account->id : $account->id,
            'Name' => $account->name,
            'AddressLine3' => $account->name_2 ?? '',
            'AddressLine2' => $account->addition ?? '',
            'AddressLine1' => $account->street . ' ' . $account->house_number,
            'Postcode' => $account->zip_code,
            'City' => $account->city,
            'Email' => $account->company_email ?? '',
            'Phone' => $account->company_phone ?? '',
            'Status' => $account->customer_type ?? 'C',
            'VATNumber' => $account->language->code == 'CH' ? '' : str_replace(['.', '-'], '', $account->ustid),
            'Country' => $account->language->code,
            'SalesVATCode' => $accounting['vatCode'],
            'GLAccountSales' => $accounting['accountSales'],
            'PriceList' => $this->getPriceListId('VK Preisliste Shop')
        ];

        if ($digitalBill) {
            $data['InvoicingMethod'] = 2;
        }

        return $this->post('/api/v1/'. $this->division .'/crm/Accounts', $data)->d->ID;
    }

    /**
     * Create a new contact (Customer User)
     *
     * @param  $contact
     * @param  $accountId
     * @return String
     */
    public function createContact($contact, $accountId)
    {
        $data = [
            'Account' => $accountId,
            'FirstName' => $contact->first_name ?? '',
            'LastName' => $contact->last_name,
            'Email' => $contact->email,
            'Phone' => $contact->phone,
            'Title' => strtoupper($contact->salutation),
            'JobTitleDescription' => $contact->position
        ];

        return $this->post('/api/v1/'. $this->division .'/crm/Contacts', $data)->d->ID;
    }

    /**
     * Create a new address (Customer Delivery Address)
     *
     * @param $address
     * @param $accountId
     * @return String
     */
    public function createAddress($address, $accountId)
    {
        $data = [
            'Account' => $accountId,
            'AddressLine1' => $address->delivery_street . ' ' . $address->delivery_house_number,
            'AddressLine2' => $address->delivery_additional,
            'AddressLine3' => $address->delivery_name,
            'Postcode' => $address->delivery_zip_code,
            'City' => $address->delivery_city,
            'Country' => $address->language->code ?? 'DE',
            'Type' => 4
        ];

        return $this->post('/api/v1/'. $this->division .'/crm/Addresses', $data)->d->ID;
    }
}