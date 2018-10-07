<?php

namespace Andchir\OmnipayBundle\Service;

use Symfony\Component\HttpFoundation\Session\Session;
use AppBundle\Document\Payment;
use Omnipay\Common\AbstractGateway;
use Omnipay\Omnipay as OmnipayCore;
use Omnipay\Omnipay;
use Psr\Log\LoggerInterface;

class OmnipayService
{
    /** @var AbstractGateway */
    protected $gateway;
    /** @var array */
    protected $config;
    /** @var LoggerInterface */
    private $logger;
    /** @var Session */
    private $session;

    public function __construct(LoggerInterface $logger, Session $session, array $config = [])
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->session = $session;
    }

    /**
     * @param $gatewayName
     * @return bool
     */
    public function create($gatewayName)
    {
        if (!isset($this->config['gateways'][$gatewayName])) {
            return false;
        }
        $this->gateway = Omnipay::create($gatewayName);
        return true;
    }

    /**
     * @return AbstractGateway
     */
    public function getGateway()
    {
        return $this->gateway;
    }

    /**
     * Set gateway parameters
     */
    public function setGatewayParameters()
    {
        $gatewayName = $this->gateway->getShortName();
        $parameters = isset($this->config['gateways'][$gatewayName])
            ? $this->config['gateways'][$gatewayName]
            : [];
        foreach($parameters as $paramName => $value){
            $methodName = 'set' . $paramName;
            if (!empty($value) && method_exists($this->gateway, $methodName)) {//case-insensitive
                call_user_func(array($this->gateway, $methodName), $value);
            }
        }
    }

    /**
     * @param string $optionName
     * @param mixed $optionValue
     * @param string $gatewayName
     */
    public function setConfigOption($optionName, $optionValue, $gatewayName = '')
    {
        if (!$gatewayName) {
            $gatewayName = $this->gateway->getShortName();
        }
        $this->config['gateways'][$gatewayName][$optionName] = $optionValue;
    }

    /**
     * @param $options
     * @param string $gatewayName
     */
    public function setConfigOptions($options, $gatewayName = '')
    {
        foreach ($options as $optionName => $optionValue) {
            $this->setConfigOption($optionName, $optionValue, $gatewayName);
        }
    }

    /**
     * @param $type
     * @return mixed|string
     */
    public function getConfigUrl($type)
    {
        return isset($this->config[$type.'_url'])
            ? $this->config[$type.'_url']
            : $this->config['fail_url'];
    }

    /**
     * @param $optionName
     * @return string
     */
    public function getConfigOption($optionName)
    {
        $gatewayName = $this->gateway->getShortName();
        return isset($this->config['gateways'][$gatewayName][$optionName])
            ? $this->config['gateways'][$gatewayName][$optionName]
            : '';
    }

    /**
     * @return array
     */
    public function getGatewayParameters()
    {
        return $this->gateway->getParameters();
    }

    /**
     * @return string
     */
    public function getGatewayName()
    {
        return $this->gateway->getName();
    }

    /**
     * @return boolean
     */
    public function getGatewaySupportsAuthorize()
    {
        return $this->gateway->supportsAuthorize();
    }

    /**
     * @return array
     */
    public function getGatewayDefaultParameters()
    {
        return $this->gateway->getDefaultParameters();
    }

    /**
     * @param $paymentId
     * @param $description
     * @param $options
     * @return \Omnipay\Common\Message\RequestInterface
     */
    public function createPurchase($paymentId, $description, $options)
    {
        $purchase = $this->gateway->purchase($options);
        $purchase->setTransactionId($paymentId);
        $purchase->setDescription($description);
        if ($this->getConfigOption('returnUrl')) {
            $purchase->setReturnUrl($this->getConfigOption('returnUrl'));
        }
        if ($this->getConfigOption('cancelUrl')) {
            $purchase->setCancelUrl($this->getConfigOption('cancelUrl'));
        }
        if ($this->getConfigOption('notifyUrl')) {
            $purchase->setNotifyUrl($this->getConfigOption('notifyUrl'));
        }
        return $purchase;
    }

    /**
     * @param Payment $payment
     * @param $paymentDescription
     * @return bool
     */
    public function sendPurchase(Payment $payment, $paymentDescription)
    {
        $this->setGatewayParameters();

        $purchase = $this->createPurchase(
            $payment->getId(),
            $paymentDescription,
            [
                'amount' => OmnipayService::toDecimal($payment->getAmount()),
                'currency' => $payment->getCurrency()
            ]
        );
        $response = $purchase->send();

        // Save data in session
        $paymentData = [
            'transactionId' => $payment->getId(),
            'email' => $payment->getEmail(),
            'userId' => $payment->getUserId(),
            'amount' => $payment->getAmount(),
            'currency' => $payment->getCurrency(),
            'gatewayName' => $this->getGatewayName()
        ];
        $this->session->set('paymentData', $paymentData);

        $this->logInfo(json_encode($paymentData) . " Order ID: {$payment->getUserId()}", 'start');

        // Process response
        if ($response->isSuccessful()) {

            // Payment was successful
            // print_r($response);

        } elseif ($response->isRedirect()) {
            $response->redirect();
        } else {
            // Payment failed
            echo $response->getMessage();
        }
        return true;
    }

    /**
     * @param $message
     * @param $source
     */
    public function logInfo($message, $source)
    {
        $this->logger->info($message, ['omnipay' => $source]);
    }

    /**
     * Number to decimal
     * @param $number
     * @return string
     */
    public static function toDecimal( $number )
    {
        $number = number_format($number, 2, '.', '');
        return $number;
    }
}
