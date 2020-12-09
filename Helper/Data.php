<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Magecitron\Dphwebhook\Helper;

use Exception;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\Adapter\CurlFactory;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Information;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Zend_Http_Response;

/**
 * Class Data
 * @package Magecitron\Dphwebhook\Helper
 */
class Data extends AbstractHelper
{

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;
    
    /**
     * @var CurlFactory
     */
    protected $curlFactory;
    
     /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;


    /**
     * @var CustomerRepositoryInterface
     */
    protected $customer;
   
    /**
     * @var OrderRepositoryInterface
     */
    protected $orderDetails;
    
    /**
     * @var StoreInformation
     */
    protected $storeInfo;
   
    /**
     * @var TimezoneInterface
     */
    protected $timezone;
   
     /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;
    
    
    /**
     * Base path to DPH Integ Webhooks configuration values
     */
    
     const XML_PATH_WEBHOOKS = 'system/magecitron_dphwebhook';
     const XML_WEBHOOKS_ENABLED = self::XML_PATH_WEBHOOKS . '/enable_webhooks';
     const XML_WEBHOOKS_STACK_TRACE_ENABLED = self::XML_PATH_WEBHOOKS . '/enable_stack_trace';
     const XML_WEBHOOKS_WEBHOOK_URL = self::XML_PATH_WEBHOOKS . '/webhook_url';
     const XML_WEBHOOKS_PASSWORD = self::XML_PATH_WEBHOOKS . '/webhook_password';
     const XML_WEBHOOKS_USER = self::XML_PATH_WEBHOOKS . '/webhook_user';
    
    /**
     * Data constructor.
     *
     * @param Context $context
     * @param ObjectManagerInterface $objectManager
     * @param StoreManagerInterface $storeManager
     * @param CurlFactory $curlFactory
     * @param CustomerRepositoryInterface $customer
     * @param OrderRepositoryInterface $orderDetails
     * @param Information $storeInfo
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        ObjectManagerInterface $objectManager,
        CurlFactory $curlFactory,
        CustomerRepositoryInterface $customer,
        OrderRepositoryInterface $orderDetails,
        Information $storeInfo,
        TimezoneInterface $timezone,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->curlFactory      = $curlFactory;
        $this->customer         = $customer;
        $this->orderDetails     = $orderDetails;
        $this->storeInfo        = $storeInfo;
        $this->timezone         = $timezone;
        $this->scopeConfig      = $scopeConfig;
        $this->objectManager    = $objectManager;
        $this->storeManager     = $storeManager;

        parent::__construct($context);
    }

     /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_WEBHOOKS_ENABLED);
    }
 
    /**
     * @return bool
     */
    public function isStackTraceEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_WEBHOOKS_STACK_TRACE_ENABLED);
    }
 
    /**
     * @return mixed
     */
    public function getWebhookURL()
    {
        return $this->scopeConfig->getValue(self::XML_WEBHOOKS_WEBHOOK_URL);
    }
 
    /**
     * @return mixed
     */
    public function getWebhookPassword()
    {
        return $this->scopeConfig->getValue(self::XML_WEBHOOKS_PASSWORD);
    }
 
    /**
     * @return mixed
     */
    public function getWebhookUser()
    {
        return $this->scopeConfig->getValue(self::XML_WEBHOOKS_USER);
    }
 
    
    /**
     * @param $item
     *
     * @return int
     * @throws NoSuchEntityException
     */
    public function getItemStore($item)
    {
        return $item->getData('store_id') ?: $this->storeManager->getStore()->getId();
    }

    /**
     * @param $item
     * @param $hookType
     *
     * @throws NoSuchEntityException
     */
    public function send($item)
    {

        try {
                $result = $this->sendHttpRequestFromHook($item);
            } catch (Exception $e) {
                $result = [
                    'success' => false,
                    'message' => $e->getMessage()
                ];
            }
    }

    /**
     * @param $hook
     * @param bool $item
     * @param bool $log
     *
     * @return array
     */
    public function sendHttpRequestFromHook($item = false, $log = false)
    {
        $url            = '';
        $authentication = '';
        $method         = 'POST';
        $body        = '';
        $headers     = [[]];

        $result = $this ->getUserCredentials();
        
            if( $result['success']===true){
                $headers =$result['headers'];
                $url = $result['url'];
                $body = $this ->getOrderInfo($item);
            
        }
                else{ return '';
        }     

        $contentType = 'application/json';
        return $this->sendHttpRequest($headers, $authentication, $contentType, $url, $body, $method);
    }

    /**
     * @param $headers
     * @param $authentication
     * @param $contentType
     * @param $url
     * @param $body
     * @param $method
     *
     * @return array
     */
    public function sendHttpRequest($headers, $authentication, $contentType, $url, $body, $method)
    {
        if (!$method) {
            $method = 'GET';
        }
        if ($headers && !is_array($headers)) {
            $headers = $this::jsonDecode($headers);
        }
        $headersConfig = [];

        foreach ($headers as $header) {
            $key             = $header['name'];
            $value           = $header['value'];
            $headersConfig[] = trim($key) . ': ' . trim($value);
        }
        
        if ($authentication) {
            $headersConfig[] = 'Authorization: ' . $authentication;
        }

        if ($contentType) {
            $headersConfig[] = 'Content-Type: ' . $contentType;
        }

        $curl = $this->curlFactory->create();
        $curl->write($method, $url, '1.1', $headersConfig, $body);

        $result = ['success' => false];

        try {
            $resultCurl         = $curl->read();
            $result['response'] = $resultCurl;
            if (!empty($resultCurl)) {
                $result['status'] = Zend_Http_Response::extractCode($resultCurl);
                if (isset($result['status']) && in_array($result['status'], [200, 201])) {
                    $result['success'] = true;
                } else {
                    $result['message'] = __('Cannot connect to server. Please try again later.');
                }
            } else {
                $result['message'] = __('Cannot connect to server. Please try again later.');
            }
        } catch (Exception $e) {
            $result['message'] = $e->getMessage();
        }
        $curl->close();

        return $result;
    }

    /**
     * @param $item
     * @param $hookType
     *
     * @throws NoSuchEntityException
     */
    public function sendObserver($item)
    {
        if (!$this->isEnabled()) {
            return;
        }

         try {
                    $result = $this->sendHttpRequestFromHook($item);
                } catch (Exception $e) {
                    $result = [
                        'success' => false,
                        'message' => $e->getMessage()
                    ];
                }
    }

    /**
     * @return int
     * @throws NoSuchEntityException
     */
    public function getStoreId()
    {
        return $this->storeManager->getStore()->getId();
    }

    /**
     * @param $classPath
     *
     * @return mixed
     */
    public function getObjectClass($classPath)
    {
        return $this->objectManager->create($classPath);
    }

     /**
     * @param $baseUrl
     *
     * @return mixed
     */
     public function getAuthenticationToken($baseUrl)
    {
        $url            = "{$baseUrl}/login"; 
        $method         = 'POST';
        $email          = $this->getWebhookUser();      //'api@sandbox.magento';
        $password       = $this->getWebhookPassword();  //'ku7xdpfi';
        
        $body        = "{\"email\":\"{$email}\",\"password\":\"{$password}\"}";
        $headers     = [];

        
        $authentication = '';
        $contentType = 'application/json';
        
        $result = $this->sendHttpRequest($headers, $authentication, $contentType, $url, $body, $method);
        $result['response'] =  Zend_Http_Response::extractBody($result['response']);
        
        if($result['success'] === true){
                $result['data'] = json_decode($result['response'],true);
                $result['data'] = $result['data']['results'];
        }

        return $result;
    }

    /**
     * @param $hook
     *
     * @return mixed
     */
     public function getPartners($baseUrl)
    {
        $authInfo          = $this->getAuthenticationToken($baseUrl);
        
        if($authInfo['success'] === true){             
           $apiKey = $authInfo['data']['apiKey'];
           $token = $authInfo['data']['sessionToken'];
        }        
        
        $url            = "{$baseUrl}/getPartners?apikey={$apiKey}"; 
        $method         = 'GET';
        
        $headers     = [["name"=> 'Auth-Token',"value"=> $token]];

        
        $authentication = $hook->getAuthentication();
        $contentType = $hook->getContentType();
        $body = false;
        
        $result = $this->sendHttpRequest($headers, $authentication, $contentType, $url, $body, $method);
        $result['response'] =  Zend_Http_Response::extractBody($result['response']);
        
        if($result['success'] === true){
                $result['data'] = json_decode($result['response'],true);
                $result['data'] = $result['data']['results'];
        }

        return $result;
    }

    /**
     * @param $baseUrl
     * @param $authInfo
     * @return mixed
     */
     public function getPartnersWithAuthInfo($baseUrl, $authInfo)
    {
        $apiKey = $authInfo['apiKey'];
        $token  = $authInfo['token'];      
        
        $url            = "{$baseUrl}/getPartners?apiKey={$apiKey}"; 
        $method         = 'GET';
        
        $headers     = [["name"=> 'Auth-Token',"value"=> $token]];

        
        $authentication = '';
        $contentType = 'application/json';
        $body = '';
        
        $result = $this->sendHttpRequest($headers, $authentication, $contentType, $url, $body, $method);
        $result['response'] =  Zend_Http_Response::extractBody($result['response']);
        
        if($result['success'] === true){
                $result['data'] = json_decode($result['response'],true);
                $result['data'] = $result['data']['results'];
        }

        return $result;
    }

    /**
     * @param $hook
     * @return mixed
     */
     public function getUserCredentials()
    {
         $baseUrl           = $this->getWebhookURL();
         $result            = ['success' => false];
         $authInfo          = $this->getAuthenticationToken($baseUrl);

        if($authInfo['success'] === true){
            
           $apiKey = $authInfo['data']['apiKey'];
           $token = $authInfo['data']['sessionToken'];
           
           $headers     = [["name"=> 'Auth-Token',"value"=> $token]];
           $credentials = ["apiKey"=>$apiKey,"token"=>$token];
           
           $partnerInfo          = $this->getPartnersWithAuthInfo($baseUrl, $credentials);  
           
            if($partnerInfo['success'] === true){ 
                $partnerId = $partnerInfo['data'][0]['id'];
            }
            
            if(isset($apiKey) && isset($partnerId)){
                $url            = "{$baseUrl}/createPostv2?apiKey={$apiKey}&partnerId={$partnerId}"; 
                
                $result['headers'] = $headers;
                $result['url'] =  $url;
                $result['success'] = true;
            }
        }  

        return $result;
    }

    /**
     * @param $shippingMethod
     * @return mixed
     */
     public function getDphConfiguration($shippingMethod)
    {
        $baseUrl           = $this->getWebhookURL();
        $authInfo          = $this->getAuthenticationToken($baseUrl);
        
        $apiKey = $authInfo['apiKey'];
        $token  = $authInfo['token'];      
        
        $url            = "{$baseUrl}/getShippingInfo?apiKey={$apiKey}&shippingMethod={$shippingMethod}"; 
        $method         = 'GET';
        
        $headers     = [["name"=> 'Auth-Token',"value"=> $token]];

        
        $authentication = '';
        $contentType = 'application/json';
        $body = '';
        
        $result = $this->sendHttpRequest($headers, $authentication, $contentType, $url, $body, $method);
        $result['response'] =  Zend_Http_Response::extractBody($result['response']);
        
        if($result['success'] === true){
                $result['data'] = json_decode($result['response'],true);
                $result['data'] = $result['data']['results'];
        }

        return $result;
    }

    /**
     * @param $item
     * @return mixed
     */
     public function getOrderInfo($item)
    {
        
         $orderId = $item ->getEntityId();
         $orderInfo = $this-> orderDetails->get($orderId);
         
         // Get Store Information
         $storeId = $item ->getStoreId();
         $store = $this-> storeManager->getStore($storeId);
         $storeInfo = $this->storeInfo->getStoreInformationObject($store);
         
         $storeName = $storeInfo->getName();
         $phone = $storeInfo->getPhone();
         $city = $storeInfo->getCity();
         $region = $storeInfo->getRegionId();
         $postcode = $storeInfo->getPostcode();
         $stLine1 = $storeInfo->getData('street_line1');
         $stLine2 = $storeInfo->getData('street_line2');
         
         $referenceNo =  $orderInfo->getIncrementId();
         $createdDate = $this->timezone->formatDateTime($orderInfo->getCreatedAt());
         
         
         $pickupDate = date('Y-m-d\TH:i',strtotime('+30 minutes',strtotime($createdDate)));
         $deliveryDate = date('Y-m-d\TH:i',strtotime('+1 hour +30 minutes',strtotime($createdDate)));
         
         $shippingMethodInfo = $orderInfo->getShippingDescription();
         
         /*$shippingMethodInfo = $this->getDphConfiguration($orderInfo->getShippingDescription());
         if(isset($shippingMethodInfo)){
             
         }*/
   
         $payLoad['refNo'] = $referenceNo;
         $payLoad['pickupDetails'] =['customerName'=> $storeName,
             'contactNumber'=> $phone,'completionDateTime'=>$pickupDate,
             'pickupAddress'=> "{$stLine1} {$stLine2}",
             'pickupCity' => $city,'province'=>$region,
             'postalCode'=>$postcode];
         
            // get customer details
            $custLastName = $orderInfo->getCustomerLastname();
            $custFirstName = $orderInfo->getCustomerFirstname();

            // get shipping details      
            $shippingAddress = $item->getShippingAddress();        
            $shippingCity = $shippingAddress->getCity();
            $shippingStreet = $shippingAddress->getStreet();
            $shippingPostcode = $shippingAddress->getPostcode();      
            $shippingTelephone = $shippingAddress->getTelephone();
            $shippingState = $shippingAddress->getData('region');

            $grandTotal = floatval($orderInfo->getGrandTotal());
            $subTotal = floatval($orderInfo->getSubtotal());
          
            
             $payLoad['deliveries'] =[['customerName'=> "$custFirstName $custLastName",
             'contactNumber'=> $shippingTelephone,'completionDateTime'=>$deliveryDate,
             'deliveryAddress'=> "$shippingStreet[0]",
             'deliveryCity' => $shippingCity,'province'=>$shippingState,
             'postalCode'=>$shippingPostcode,'itemPrice'=>$subTotal,
             'codAmount'=>$grandTotal]];
           
           $body        = json_encode($payLoad);

        return $body;
    }
}