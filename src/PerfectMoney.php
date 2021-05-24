<?php

namespace Oasin\PerfectMoney;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Oasin\PerfectMoney\Exceptions\PerfectMoneyException;
use Oasin\PerfectMoney\Events\PerfectMoneyPaymentIncome;
use Oasin\PerfectMoney\Events\PerfectMoneyPaymentCancel;

class PerfectMoney implements  PerfectMoneyInterface
{
    use ValidatesRequests;

    protected $memo;
    protected $accountID;
    protected $accountName;
    protected $account;
    protected $passphrase;
    protected $password;


    /**
     * Internal storage of all of the parameters.
     *
     * @var ParameterBag
     */
    protected $parameters;

    /**
     * Set one parameter.
     *
     * @param string $key Parameter key
     * @param mixed $value Parameter value
     * @return $this
     */
    public function setParameter( $key, $value = null)
    {
        $this->$key = $value;
        return $this;
    }


    public function getParameter($key)
    {
        return $this->$key;
    }


    public function getAccount()
    {
        return $this->getParameter('account');
    }


    /**
     * @param string $value
     * @return PerfectMoney
     */
    public function setAccount($value)
    {
        return $this->setParameter('account', $value);
    }

    /**
     * @return string
     */
    public function getAccountId()
    {
        return $this->getParameter('accountID');
    }

    /**
     * @param $value
     * @return Gateway
     */
    public function setAccountID($value)
    {
        return $this->setParameter('accountID', $value);
    }

    /**
     * @return string
     */
    public function getAccountName()
    {
        return $this->getParameter('accountName');
    }

    /**
     * @param $value
     * @return PerfectMoney
     */
    public function setAccountName($value)
    {
        return $this->setParameter('accountName', $value);
    }

    /**
     * @return string
     */
    public function getPassphrase()
    {
        return $this->getParameter('passphrase');
    }

    /**
     * @param $value
     * @return Gateway
     */
    public function setPassphrase($value)
    {
        return $this->setParameter('passphrase', $value);
    }

    /**
     * @return string
     */
    public function getPassword()
    {
        return $this->getParameter('password');
    }

    /**
     * @param $value
     * @return Gateway
     */
    public function setPassword($value)
    {
        return $this->setParameter('password', $value);
    }

    public function memo($memo)
    {
        $this->memo = $memo;
        return $this;
    }



    /**
     * @param string $unit
     * @return float
     * @throws \Exception
     */
    function balance($unit = "USD")
    {
        $client = new \GuzzleHttp\Client();
        try {
            $res = $client->request('GET', 'https://perfectmoney.is/acct/balance.asp', [
                'query' => [
                    "AccountID" =>  $this->accountID ?? config('perfectmoney.account_id'),
                    "PassPhrase" => $this->password ?? config('perfectmoney.account_pass')
                ]
            ]);
        } catch (GuzzleException $e) {
            throw new \Exception($e->getMessage());
        }

        preg_match_all("/<input name='ERROR' type='hidden' value='(.*)'>/", $res->getBody(), $result, PREG_SET_ORDER);

        if ($result) {
            throw new \Exception($result[0][1]);
        }
        preg_match_all("/<input name='" . config('perfectmoney.payee_account') . "' type='hidden' value='(.*)'>/", $res->getBody(), $result, PREG_SET_ORDER);
        return $result[0][1];
    }

    /**
     * @param int $payment_id
     * @param float $sum
     * @param string $units
     * @return string
     */
    function form($payment_id, $sum, $units = 'USD')
    {
        $sum = number_format($sum, 2, ".", "");
        $form_data = array(
            "PAYEE_ACCOUNT" => $this->account ?? config('perfectmoney.payee_account'),
            "PAYEE_NAME" =>  $this->accountName ?? config('perfectmoney.account_name'),
            "PAYMENT_ID" => $payment_id,
            "PAYMENT_AMOUNT" => $sum,
            "PAYMENT_UNITS" => $units,
            "STATUS_URL" => route('perfectmoney.confirm'),
            "PAYMENT_URL" => route('perfectmoney.after_pay_to_cab'),
            "PAYMENT_URL_METHOD" => "POST",
            "NOPAYMENT_URL" => route('perfectmoney.cancel'),
            "PAYER_ACCOUNT" => "",
            "NOPAYMENT_URL_METHOD" => "POST",
            "SUGGESTED_MEMO" => ($this->memo) ? $this->memo : null,
        );
        ob_start();
        $output = '';
        $output .= '<form class="form_payment" id="payment_form" action="https://perfectmoney.com/api/step1.asp" method="POST">';
        foreach ($form_data as $key => $value) {
            $output .= '<input type="hidden" name="' . $key . '" value="' . $value . '">';
        }
        $output .= '<input type="submit" style="width:0;height:0;border:0px; background:none;" class="content__login-submit submit_pay_ok" name="PAYMENT_METHOD" value="">';
        $output .= '</form>';
        $output .= '<script>document.getElementById("payment_form").submit();</script>';
        echo $output;
        $content = ob_get_contents();
        ob_end_clean();
        return $content;
    }

    /**
     * @param Request $request
     * @return bool
     */
    public
    function validateIPNRequest(Request $request)
    {
        return $this->check_transaction($request->all(), $request->server(), $request->headers);
    }

    /**
     * @param array $post_data
     * @param array $server_data
     * @return bool
     * @throws PerfectMoneyException
     */
    public
    function validateIPN(array $post_data, array $server_data)
    {
        if (!isset($post_data['PAYMENT_ID'])) {
            throw new PerfectMoneyException("For validate IPN need order id");
        }

        if ($post_data['PAYMENT_AMOUNT'] <= 0) {
            throw new PerfectMoneyException("Need amount for transaction");
        }

        if ($post_data['PAYEE_ACCOUNT'] != $this->account ?? config('perfectmoney.payee_account')) {
            throw new PerfectMoneyException("Payeer dont admin account");
        }

        $PAYMENT_ID = $post_data['PAYMENT_ID'];
        $PAYMENT_AMOUNT = $post_data['PAYMENT_AMOUNT'];
        $PAYMENT_BATCH_NUM = $post_data['PAYMENT_BATCH_NUM'];
        $PAYER_ACCOUNT = $post_data['PAYER_ACCOUNT'];
        $TIMESTAMPGMT = $post_data['TIMESTAMPGMT'];
        $V2_HASH = $post_data['V2_HASH'];
        $PAYEE_ACCOUNT = $post_data['PAYEE_ACCOUNT'];
        $alt_pass = $this->passphrase ?? config('perfectmoney.alt');

        $sign = @$PAYMENT_ID . ":" . $this->account ?? config('perfectmoney.payee_account') . ":" . @$PAYMENT_AMOUNT . ":USD:" . @$PAYMENT_BATCH_NUM . ":" . @$PAYER_ACCOUNT . ":" . strtoupper(md5($alt_pass)) . ":" . @$TIMESTAMPGMT;
        $sign = strtoupper(md5($sign));

        if ($sign !== $V2_HASH) {
            throw new PerfectMoneyException("Missing sign !== V2 Hash");
        }

        return true;
    }

    /**
     * @param array $request
     * @param array $server
     * @param array $headers
     * @return bool
     */
    function check_transaction(array $request, array $server, $headers = [])
    {
       
        $textReponce = [
            'status' => 'success'
        ];
        try {
            $is_complete = $this->validateIPN($request, $server);
            if ($is_complete) {
                $PassData = new \stdClass();
                $PassData->amount = $request['PAYMENT_AMOUNT'];
                $PassData->payment_id = $request['PAYMENT_ID'];
                $PassData->transaction = $request['PAYMENT_BATCH_NUM'];
                $PassData->add_info = [
                    "full_data_ipn" => $request
                ];
                event(new PerfectMoneyPaymentIncome($PassData));
                return \Response::json($textReponce, "200");
            }
        } catch (PerfectMoneyException $e) {
            //log IPN error 

            // $e->getMessage();
        }
        return \Response::json($textReponce, "200");
    }

    /**
     * @param int $payment_id
     * @param float $amount
     * @param $address
     * @param string $currency
     * @return bool|\stdClass
     * @throws GuzzleException
     * @throws \Exception
     */
    function send_money($payment_id, $amount, $address, $currency)
    {
        $amount = number_format($amount, 2, ".", "");
        $client = new \GuzzleHttp\Client();
        $res = $client->request('GET', 'https://perfectmoney.is/acct/confirm.asp', [
            'query' => [
                'AccountID' => $this->accountID ?? config('perfectmoney.account_id'),
                'PassPhrase' => $this->password ?? config('perfectmoney.account_pass'),
                'Payer_Account' => $this->account ?? config('perfectmoney.payee_account'),
                'Payee_Account' => strtoupper(trim($address)),
                'Amount' => $amount,
                'PAY_IN' => $amount,
                'Memo' => $this->memo,
                'PAYMENT_ID' => $payment_id
            ]
        ]);

        preg_match_all("/<input name='ERROR' type='hidden' value='(.*)'>/", $res->getBody(), $result, PREG_SET_ORDER);
        if ($result) {
            throw new \Exception($result[0][1]);
        }

        preg_match_all("/<input name='(.*)' type='hidden' value='(.*)'>/", $res->getBody(), $result, PREG_SET_ORDER);


        $rezult = [];
        foreach ($result as $item) {
            $rezult[$item[1]] = $item[2];
        }

        $PassData = new \stdClass();
        $PassData->transaction = $rezult['PAYMENT_BATCH_NUM'];
        $PassData->sending = true;
        $PassData->add_info = [
            "fee" => $amount * 0.5 / 100,
            "full_data" => $rezult
        ];
        return $PassData;
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    function cancel_payment(Request $request)
    {
        $PassData = new \stdClass();
        $PassData->id = $request->input('PAYMENT_ID');

        event(new PerfectMoneyPaymentCancel($PassData));

        return redirect(config('perfectmoney.to_account'));
    }
}