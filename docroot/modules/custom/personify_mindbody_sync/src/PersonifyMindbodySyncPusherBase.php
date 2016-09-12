<?php

namespace Drupal\personify_mindbody_sync;

use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Logger\LoggerChannel;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\environment_config\EnvironmentConfigServiceInterface;
use Drupal\mindbody\MindbodyException;
use Drupal\mindbody_cache_proxy\MindbodyCacheProxyInterface;
use Drupal\personify_mindbody_sync\Entity\PersonifyMindbodyCache;
use Drupal\ymca_mappings\LocationMappingRepository;

/**
 * Class PersonifyMindbodySyncPusherBase.
 *
 * @package Drupal\personify_mindbody_sync
 */
abstract class PersonifyMindbodySyncPusherBase implements PersonifyMindbodySyncPusherInterface {

  /**
   * Test client ID.
   */
  const TEST_CLIENT_ID = '2052596923';

  /**
   * Drupal\personify_mindbody_sync\PersonifyMindbodySyncWrapper definition.
   *
   * @var PersonifyMindbodySyncWrapper
   */
  protected $wrapper;

  /**
   * The logger channel.
   *
   * @var LoggerChannelInterface
   */
  protected $logger;

  /**
   * Config factory.
   *
   * @var ConfigFactory
   */
  protected $config;

  /**
   * Array of Client IDs for processing to Mindbody.
   *
   * @var array
   */
  protected $clientIds = [];

  /**
   * MindBody cache client.
   *
   * @var \Drupal\mindbody_cache_proxy\MindbodyCacheProxyInterface
   */
  protected $client;

  /**
   * The list of services.
   *
   * @var array
   */
  protected $services;

  /**
   * Is production flag.
   *
   * @var bool
   */
  protected $isProduction;

  /**
   * Mindbody config.
   *
   * @var array
   */
  protected $mindbodyConfig;

  /**
   * Mail manager.
   *
   * @var MailManagerInterface
   */
  protected $mailManager;

  /**
   * The location repo.
   *
   * @var LocationMappingRepository
   */
  protected $locationRepo;

  /**
   * PersonifyMindbodySyncPusher constructor.
   *
   * @param PersonifyMindbodySyncWrapper $wrapper
   *   Data wrapper.
   * @param MindbodyCacheProxyInterface $client
   *   MindBody caching client.
   * @param ConfigFactory $config
   *   Config factory.
   * @param LoggerChannelInterface $logger
   *   The logger channel.
   * @param EnvironmentConfigServiceInterface $env_config
   *   Environment config.
   * @param MailManagerInterface $mail_manager
   *   Mail manager.
   * @param LocationMappingRepository $location_repo;
   *   The Location repo.
   */
  public function __construct(PersonifyMindbodySyncWrapper $wrapper, MindbodyCacheProxyInterface $client, ConfigFactory $config, LoggerChannelInterface $logger, EnvironmentConfigServiceInterface $env_config, MailManagerInterface $mail_manager, LocationMappingRepository $location_repo) {
    $this->wrapper = $wrapper;
    $this->logger = $logger;
    $this->client = $client;
    $this->config = $config;
    $this->mailManager = $mail_manager;
    $this->locationRepo = $location_repo;
    $this->mindbodyConfig = $env_config->getActiveConfig('mindbody.settings');

    // Check the mode.
    $settings = $this->config->get('personify_mindbody_sync.settings');
    $this->isProduction = (bool) $settings->get('is_production');
  }

  /**
   * Push orders.
   */
  protected function pushOrders() {
    $source = $this->wrapper->getSourceData();

    $locations = $this->getAllLocationsFromOrders($source);
    foreach ($locations as $location => $count) {
      // Obtain Service ID.
      $params = [
        'LocationID' => $location,
        'HideRelatedPrograms' => TRUE,
      ];

      try {
        $response = $this->client->call(
          'SaleService',
          'GetServices',
          $params,
          FALSE
        );
      }
      catch (MindbodyException $e) {
        $msg = 'Failed to get services form Mindbody: %error';
        $this->logger->critical($msg, ['%error' => $e->getMessage()]);
        return;
      }

      $this->services[$location] = $response->GetServicesResult->Services->Service;
    }

    // Loop through orders.
    $all_orders = [];

    $pushed = 0;
    foreach ($source as $id => $order) {
      $cache_entity = $this->wrapper->findOrder($order->OrderNo, $order->OrderLineNo);
      if (!$cache_entity) {
        $this->logger->error('Failed to find entity in the local cache.');
        continue;
      }

      // Do not push the order if it's already pushed.
      // @todo Need to check whether the order stats has been changed.
      $order_data = $cache_entity->get('field_pmc_ord_data');
      if (!$order_data->isEmpty()) {
        // Just skip this order.
        continue;
      }

      $service = $this->getServiceByProductCode($order->ProductCode, $order->RateStructure);
      if (!$service) {
        $msg = 'Failed to find a service with the code: %code';
        $this->logger->error($msg, ['%code' => $order->ProductCode]);
        continue;
      }

      // In test mode proceed orders only for test user.
      if (!$this->isProduction && $order->MasterCustomerId != self::TEST_CLIENT_ID) {
        continue;
      }

      $client_id = $this->isProduction ? $order->MasterCustomerId : self::TEST_CLIENT_ID;

      // Prepare cart items.
      $cart_items = [];
      $cart_items_object = new \ArrayObject();

      for ($i = 0; $i < $order->OrderQuantity; $i++) {
        $cart_items[] = [
          'Quantity' => 1,
          'Item' => new \SoapVar(
            [
              'ID' => $service->ID,
            ],
            SOAP_ENC_ARRAY,
            'Service',
            'http://clients.mindbodyonline.com/api/0_5'
          ),
          'DiscountAmount' => 0,
        ];
      }

      foreach ($cart_items as $item) {
        $cart_items_object->append($item);
      }

      $all_orders[$order->MasterCustomerId][$order->OrderLineNo] = [
        'UserCredentials' => [
          // According to documentation we can use credentials, but with underscore at the beginning of username.
          // @see https://developers.mindbodyonline.com/Develop/Authentication.
          'Username' => '_' . $this->mindbodyConfig['sourcename'],
          'Password' => $this->mindbodyConfig['password'],
          'SiteIDs' => [
            $this->mindbodyConfig['site_id'],
          ],
        ],
        'ClientID' => $client_id,
        'CartItems' => [
          'CartItem' => $cart_items_object->getArrayCopy(),
        ],
        'Payments' => [
          'PaymentInfo' => new \SoapVar(
            [
              'Amount' => $service->Price * $order->OrderQuantity,
              // Custom payment ID?
              'ID' => 18,
            ],
            SOAP_ENC_ARRAY,
            'CustomPaymentInfo',
            'http://clients.mindbodyonline.com/api/0_5'
          ),
        ],
      ];

      // In order to obtain saleID for the order we'll use the next workaround.
      // We'll get a list of orders before the push and after the push.
      // Then we'll make a diff.
      $sale_ids_before = $this->getClientPurchases($client_id);
      if (FALSE === $sale_ids_before) {
        continue;
      }

      $current_order = $all_orders[$order->MasterCustomerId][$order->OrderLineNo];

      try {
        $response = $this->client->call(
          'SaleService',
          'CheckoutShoppingCart',
          $current_order,
          FALSE
        );
      }
      catch (MindbodyException $e) {
        $this->updateStatusByOrder($order->OrderNo, $order->OrderLineNo, $e->getMessage());

        $msg = 'Failed to push order to the MindBody: %msg';
        $this->logger->error($msg, ['%msg' => $e->getMessage()]);
        // Skip this order. Continue with next.
        continue;
      }
      if ($response->CheckoutShoppingCartResult->ErrorCode == 200) {
        // Get saleID.
        $sale_ids_after = $this->getClientPurchases($client_id);

        // When quantity more than 1, it returns duplicates. E.g. quantity 3, it will return 3 same ids.
        $sale_ids_after = array_unique($sale_ids_after);
        $diff = array_diff($sale_ids_after, $sale_ids_before);

        // Exclude order from processing if we're not able to determined SaleID.
        $sale_id = 0;
        if (1 !== count($diff)) {
          $msg = 'Got more than 1 sale ID after the diff. Order item: %order';
          $this->logger->critical($msg, ['%order' => serialize($current_order)]);
          $this->updateStatusByOrder($order->OrderNo, $order->OrderLineNo, t("Can't determine SaleID."));
          $this->sendNotification($order, $sale_id, 'notify_location_trainer_saleid');
        }
        else {
          $sale_id = reset($diff);
          $this->updateStatusByOrder($order->OrderNo, $order->OrderLineNo, $response->CheckoutShoppingCartResult->Status);
          $this->sendNotification($order, $sale_id, 'notify_location_trainers');
        }

        $cache_entity->set('field_pmc_sale_id', $sale_id);
        $cache_entity->set('field_pmc_ord_data', serialize($response->CheckoutShoppingCartResult->ShoppingCart));
        $cache_entity->save();
        $pushed++;

        $msg = 'The order ID %id with line number %num and code %code has been pushed.';
        $this->logger->info(
          $msg,
          [
            '%id' => $order->OrderNo,
            '%num' => $order->OrderLineNo,
            '%code' => $order->ProductCode,
          ]
        );

      }
      else {
        // To reproduce this just comment ID in the cart item.
        $this->updateStatusByOrder($order->OrderNo, $order->OrderLineNo, $response->CheckoutShoppingCartResult->Message);

        // Log an error.
        $msg = 'Failed to push order to MindBody: %error';
        $this->logger->critical($msg, ['%error' => serialize($response)]);
      }
    }

    $this->logger->info(
      'Fast pusher has pushed %num orders. Finished.', ['%num' => $pushed ]
    );

  }

  /**
   * Send notifications.
   *
   * @param \stdClass $order
   *   Order.
   * @param string $mb_sale_id
   *   MindBody SaleID.
   * @param string $notification_type
   *   Notification type.
   */
  private function sendNotification(\stdClass $order, $mb_sale_id, $notification_type = 'notify_location_trainers') {
    $mapping = $this->config->get('ymca_mindbody.notifications')->get('locations');

    // Build bridge Personify location -> Drupal location -> MindBody location.
    $location_personify = $this->getLocationForOrder($order);
    $location_mindbody = $this->locationRepo->findMindBodyIdByPersonifyId($location_personify);

    if (empty($location_mindbody)) {
      // There is no mindbody id for this personify location.
      return;
    }

    if (!isset($mapping[$location_mindbody])) {
      // There is no mapping for this location.
      return;
    }

    $location_mapping = $this->locationRepo->findByMindBodyId($location_mindbody);
    $tokens = [
      'client_name' => $order->FirstName . ' ' . $order->LastName,
      'item_name' => $order->ProductCode,
      'client_email' => $order->PrimaryEmail,
      'client_phone' => $order->PrimaryPhone,
      'mb_sale_id' => $mb_sale_id,
      'personify_order_no' => $order->OrderNo,
      'personify_order_line_no' => $order->OrderLineNo,
      'location' => $location_mapping->label()
    ];

    $emails = [];
    foreach ($mapping[$location_mindbody] as $trainer) {
      $tokens['trainer_name'] = $trainer['name'];
      $this->mailManager->mail('ymca_mindbody', $notification_type, $trainer['email'], 'en', $tokens);
      $emails[] = $trainer['email'];
    }

    $msg = 'Notification about order ID %id with line number %num and sale ID %sale was sent to emails: %emails';
    $this->logger->info(
      $msg,
      [
        '%id' => $order->OrderNo,
        '%num' => $order->OrderLineNo,
        '%sale' => $mb_sale_id,
        '%emails' => implode(', ', $emails)
      ]
    );

  }

  /**
   * Get client purchases.
   *
   * @param int $id
   *   Client ID.
   *
   * @return array|bool
   *   List of sale IDs.
   */
  private function getClientPurchases($id) {
    $params = [
      'SourceCredentials' => [
        'SourceName' => $this->mindbodyConfig['sourcename'],
        'Password' => $this->mindbodyConfig['password'],
        'SiteIDs' => [$this->mindbodyConfig['site_id']],
      ],
      'ClientID' => $id
    ];

    try {
      $result = $this->client->call('ClientService', 'GetClientPurchases', $params, FALSE);

      if (200 !== $result->GetClientPurchasesResult->ErrorCode) {
        $this->logger->error('Get non 200 code on ClientService with response', serialize($result->GetClientPurchasesResult));
        return FALSE;
      }

      if (!count((array) $result->GetClientPurchasesResult->Purchases)) {
        return [];
      }

      $items = [];

      $purchases = $result->GetClientPurchasesResult->Purchases;
      if (is_array($purchases->SaleItem)) {
        $list = $purchases->SaleItem;
      }
      else {
        $list = (array) $result->GetClientPurchasesResult->Purchases;
      }

      foreach ($list as $sale_item) {
        $items[] = $sale_item->Sale->ID;
      }

      return $items;
    }
    catch (MindbodyException $e) {
      $msg = 'Failed to get client purchases before the push: %msg';
      $this->logger->error($msg, ['%msg' => $e->getMessage()]);
    }

    return FALSE;
  }

  /**
   * Update appropriate cache entities with client response data.
   *
   * @param string $client_id
   *   Client ID.
   * @param mixed $data
   *   Client data.
   */
  protected function updateClientData($client_id, $data) {
    $cache_entities = $this->getEntityByClientId($client_id);
    if (empty($cache_entities)) {
      return;
    }

    foreach ($cache_entities as $cache_entity) {
      if ($cache_entity->get('field_pmc_clnt_data')->isEmpty()) {
        $cache_entity->set('field_pmc_clnt_data', serialize($data));
        $cache_entity->save();
      }
    }
  }

  /**
   * Statically cached entity getter by ID.
   *
   * @param string $id
   *   ID been searched by.
   *
   * @return PersonifyMindbodyCache|bool
   *   List of entities or FALSE.
   */
  protected function getEntityByClientId($id = '') {
    $entities = [];

    if ($id == NULL) {
      return FALSE;
    }

    $entity = &drupal_static(__FUNCTION__ . $id);
    if (isset($entity)) {
      return $entity;
    }

    $ids = \Drupal::entityQuery('personify_mindbody_cache')
      ->condition('field_pmc_user_id', $id)
      ->execute();

    if (!$ids) {
      return FALSE;
    }

    foreach ($ids as $id) {
      if (isset($this->wrapper->getProxyData()[$id])) {
        $entities[] = $this->wrapper->getProxyData()[$id];
      }
    }

    if (empty($entities)) {
      return FALSE;
    }

    return $entities;
  }

  /**
   * Get service ID by Product Code.
   *
   * @param string $code
   *   Product code.
   *
   * @return mixed
   *   Service ID.
   */
  protected function getServiceByProductCode($code, $member_type) {
    $map_legacy = [
      'PT_NMP_1_SESS_30_MIN' => '10101',
      'PT_12_SESS_30_MIN' => '10110',
      'PT_NMP_12_SESS_30_MIN' => '10106',
      'PT_20_SESS_30_MIN' => '10111',
      'PT_NMP_20_SESS_30_MIN' => '10107',
      'PT_3_SESS_30_MIN' => '10108',
      'PT_NMP_3_SESS_30_MIN' => '10103',
      'PT_6_SESS_30_MIN' => '10109',
      'PT_NMP_6_SESS_30_MIN' => '10104',
      'PT_1_SESS_60_MIN' => '10112',
      'PT_NMP_1_SESS_60_MIN' => '10105',
      'PT_12_SESS_60_MIN' => '10119',
      'PT_NMP_12_SESS_60_MIN' => '10115',
      'PT_20_SESS_60_MIN' => '10120',
      'PT_NMP_20_SESS_60_MIN' => '10116',
      'PT_3_SESS_60_MIN' => '10117',
      'PT_NMP_3_SESS_60_MIN' => '10113',
      'PT_6_SESS_60_MIN' => '10118',
      'PT_NMP_6_SESS_60_MIN' => '10114',
      'PT_1_SESS_30_MIN' => '10101',
      'PT_BY_NMP_1_SESS_30_M' => '10131',
      'PT_BY_MP_1_SESS_30_MI' => '10172',
      'PT_BY_MP_12_SESS_30_M' => '10174',
      'PT_BY_NMP_12_SESS_30_' => '10138',
      'PT_BY_MP_6_SESS_30_MI' => '10173',
      'PT_BY_NMP_6_SESS_30_M' => '10137',
      'PT_BY_NMP_1_SESS_60_M' => '10127',
      'PT_BY_MP_12_SESS_60_M' => '10129',
      'PT_BY_NMP_12_SESS_60M' => '10176',
      'PT_BY_MP_20_SESS_60_M' => '10130',
      'PT_BY_NMP_20_SESS_60M' => '10177',
      'PT_BY_MP_6_SESS_60_MI' => '10136',
      'PT_BY_NMP_6_SESS_60_M' => '10175',
      'PT_BY_MP_1_SESS_60_MI' => '10126',
      'PT_BY_MP_INTRO' => '10134',
    ];

    $map = [
      'Member' => [
        'PT_1_SESS_30_MIN' => '10241',
        'PT_3_SESS_30_MIN' => '10108',
        'PT_6_SESS_30_MIN' => '10109',
        'PT_12_SESS_30_MIN' => '10110',
        'PT_20_SESS_30_MIN' => '10111',
        'PT_1_SESS_60_MIN' => '10112',
        'PT_3_SESS_60_MIN' => '10117',
        'PT_6_SESS_60_MIN' => '10118',
        'PT_12_SESS_60_MIN' => '10119',
        'PT_20_SESS_60_MIN' => '10120'
      ],
      'Regular' => [
        'PT_1_SESS_30_MIN' => '10101',
        'PT_3_SESS_30_MIN' => '10103',
        'PT_6_SESS_30_MIN' => '10104',
        'PT_12_SESS_30_MIN' => '10106',
        'PT_20_SESS_30_MIN' => '10107',
        'PT_1_SESS_60_MIN' => '10105',
        'PT_3_SESS_60_MIN' => '10113',
        'PT_6_SESS_60_MIN' => '10114',
        'PT_12_SESS_60_MIN' => '10115',
        'PT_20_SESS_60_MIN' => '10116'
      ],
    ];

    preg_match("/\d+_(PT_.*)/", $code, $test);
    if (!$test[1]) {
      return FALSE;
    }

    // Service ID.
    if (!array_key_exists($test[1], $map[$member_type])) {
      return FALSE;
    }
    $id = $map[$member_type][$test[1]];

    // Location ID.
    $location_id = explode('_', $code)[0];

    foreach ($this->services as $location => $services) {
      if ($location == $location_id) {
        foreach ($services as $service) {
          if ($service->ID == $id) {
            return $service;
          }
        }
      }
    }

    return FALSE;
  }

  /**
   * Get Location ID from Order object.
   *
   * @param \stdClass $order
   *   Order to be processed.
   *
   * @return string
   *   String of LocationID.
   */
  protected function getLocationForOrder(\stdClass $order) {
    $data = explode('_', $order->ProductCode);
    return $data[0];
  }

  /**
   * Pre populate locations.
   *
   * @param array $orders
   *   Assoc array with ID as keys and count of orders as value.
   *
   * @return array
   *   Locations.
   */
  protected function getAllLocationsFromOrders(array $orders) {
    $locations = [];
    foreach ($orders as $id => $order) {
      $loc_id = $this->getLocationForOrder($order);
      if (!isset($locations[$loc_id])) {
        $locations[$loc_id] = 0;
      }
      else {
        $locations[$loc_id]++;
      }
    }
    return $locations;
  }

  /**
   * Filter out clients pushed to MindBody.
   *
   * @return mixed
   *   FALSE if there is an error.
   */
  protected function filerOutClients() {
    $data = $this->wrapper->getProxyData();

    foreach ($data as $id => $entity) {
      $user_id = $entity->field_pmc_user_id->value;
      $personifyData = unserialize($entity->field_pmc_prs_data->value);

      // Push only items which were not pushed before.
      if ($entity->get('field_pmc_clnt_data')->isEmpty()) {
        $this->clientIds[$user_id] = $this->prepareClientObject($user_id, $personifyData);
      }
    }

    // Locate already synced clients.
    try {
      $result = $this->client->call(
        'ClientService',
        'GetClients',
        ['ClientIDs' => array_keys($this->clientIds)],
        FALSE
      );
    }
    catch (MindbodyException $e) {
      $msg = 'Failed to get clients list: %error';
      $this->logger->critical($msg, ['%error' => $e->getMessage()]);
      return $this;
    }

    if ($result->GetClientsResult->ErrorCode == 200 && $result->GetClientsResult->ResultCount != 0) {
      // Got it, there are clients, pushed already.
      $remote_clients = [];
      if ($result->GetClientsResult->ResultCount == 1) {
        $remote_clients[] = $result->GetClientsResult->Clients->Client;
      }
      else {
        $remote_clients = $result->GetClientsResult->Clients->Client;
      }

      // We've found a few clients already. Let's filter them out.
      $skipped = 0;
      foreach ($remote_clients as $client) {
        // Skip users already saved into cache.
        unset($this->clientIds[$client->ID]);
        $skipped++;

        $msg = 'The client with ID %id has been skipped by fast pusher. Already pushed.';
        $this->logger->info(
          $msg, ['%id' => $client->ID]
        );

        // Update cached entity with client's data if first time.
        $this->updateClientData($client->ID, $client);
      }

      $msg = 'Fast pusher skipped %num clients. They were already pushed.';
      $this->logger->info($msg, ['%num' => $skipped]);
    }
    elseif ($result->GetClientsResult->ErrorCode != 200) {
      $msg = 'Error from MindBody: %error';
      $this->logger->critical($msg, ['%error' => serialize($result)]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Prepare SoapVar object from Personify Data.
   *
   * @param int $user_id
   *   User ID.
   * @param \stdClass $data
   *   Personify data.
   *
   * @return \SoapVar
   *   Object ready to push to MindBody.
   */
  protected function prepareClientObject($user_id, \stdClass $data) {
    $default_phone = '0000000000';

    // Fix AddressLine.
    $address = 'NA';

    // Try automatically fix phone.
    if (!$phone = $data->PrimaryPhone) {
      $phone = $default_phone;
    }
    else {
      // The phone should be like: 612-865-9139.
      $result = preg_grep("/^\d{3}-\d{3}-\d{4}$/", [$phone]);
      if (empty($result)) {
        // Phone is invalid. Append it to AddressLine.
        $address .= ' PrimaryPhone: ' . $phone;
        $phone = $default_phone;
      }
    }

    return new \SoapVar(
      [
        'NewID' => $this->isProduction ? $user_id : self::TEST_CLIENT_ID,
        'ID' => $this->isProduction ? $user_id : self::TEST_CLIENT_ID,
        'FirstName' => !empty($data->FirstName) ? $data->FirstName : 'Non existent within Personify: FirstName',
        'LastName' => !empty($data->LastName) ? $data->LastName : 'Non existent within Personify: LastName',
        'Email' => !empty($data->PrimaryEmail) ? $data->PrimaryEmail : 'Non existent within Personify: Email',
        'BirthDate' => !empty($data->BirthDate) ? $data->BirthDate : '1970-01-01T00:00:00',
        'MobilePhone' => $phone,
        'AddressLine1' => $address,
        'City' => 'Non existent within Personify: City',
        'State' => 'NA',
        'PostalCode' => '00000',
        'ReferredBy' => 'Non existent within Personify: ReferredBy'
      ],
      SOAP_ENC_OBJECT,
      'Client',
      'http://clients.mindbodyonline.com/api/0_5'
    );
  }

  /**
   * Update status message by client IDs.
   *
   * @param array $ids
   *   Client IDs.
   * @param string $message
   *   A message to log.
   */
  protected function updateStatusByClients(array $ids, $message) {
    foreach ($ids as $id) {
      $entities = $this->getEntityByClientId($id);
      if (empty($entities)) {
        continue;
      }

      foreach ($entities as $entity) {
        $entity->set('field_pmc_status', 'Client: ' . $message);
        $entity->save();
      }
    }
  }

  /**
   * Update status message by Order number & line number.
   *
   * @param string $order_num
   *   Order number.
   * @param string $order_line_num
   *   Order line number.
   * @param string $message
   *   A message.
   */
  protected function updateStatusByOrder($order_num, $order_line_num, $message) {
    if (!$entity = $this->wrapper->findOrder($order_num, $order_line_num)) {
      return;
    }

    $entity->set('field_pmc_status', 'Order: ' . $message);
    $entity->save();
  }

  /**
   * Get MindBody Sale by ID.
   *
   * @param int $id
   *   ID.
   *
   * @return mixed
   *   Sale object.
   *
   * @throws \Drupal\mindbody\MindbodyException
   */
  protected function getSaleById($id) {
    $result = $this->client->call(
      'SaleService',
      'GetSales',
      ['SaleID' => 15820],
      FALSE
    );

    if (200 != $result->GetSalesResult->ErrorCode) {
      $code = $result->GetSalesResult->ErrorCode;
      $status = $result->GetSalesResult->Status;
      throw new MindbodyException('Got ' . $code . ' from MindBody with status ' . $status . '.');
    }

    return $result->GetSalesResult->Sales->Sale;
  }

  /**
   * Get sales by date range.
   *
   * @param string $start
   *   Start time: '2016-07-15T10:57:00'.
   * @param string $end
   *   End time: '2016-07-15T10:57:00'.
   *
   * @return array
   *   Sales.
   *
   * @throws \Drupal\mindbody\MindbodyException
   */
  protected function getSalesByDate($start, $end) {
    $result = $this->client->call(
      'SaleService',
      'GetSales',
      [
        'StartSaleDateTime' => $start,
        'EndSaleDateTime' => $end
      ],
      FALSE
    );

    if (200 != $result->GetSalesResult->ErrorCode) {
      $code = $result->GetSalesResult->ErrorCode;
      $status = $result->GetSalesResult->Status;
      throw new MindbodyException('Got ' . $code . ' from MindBody with status ' . $status . '.');
    }

    if ($result->GetSalesResult->ResultCount == 1) {
      $sales = [$result->GetSalesResult->Sales->Sale];
    }
    else {
      $sales = $result->GetSalesResult->Sales;
    }

    return $sales;
  }

}
