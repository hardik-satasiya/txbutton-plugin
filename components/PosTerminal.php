<?php namespace Txbutton\App\Components;

use Flash;
use Currency;
use Redirect;
use Cms\Classes\ComponentBase;
use TxButton\App\Classes\PosAuthManager;
use TxButton\App\Models\Sale as SaleModel;
use TxButton\App\Models\Wallet as WalletModel;
use ApplicationException;
use ValidationException;

class PosTerminal extends ComponentBase
{
    protected $authManager;

    public function componentDetails()
    {
        return [
            'name'        => 'PosTerminal Component',
            'description' => 'No description provided yet...'
        ];
    }

    public function defineProperties()
    {
        return [];
    }

    public function init()
    {
        $this->authManager = PosAuthManager::instance();
    }

    public function onRun()
    {
        $this->prepareVars();
    }

    protected function prepareVars()
    {
        $this->page['posUsername'] = $this->posUsername();
        $this->page['posUser'] = $this->posUser();
        $this->page['posWallet'] = $this->posWallet();
        $this->page['slideMode'] = $this->detectSlideMode();
        $this->page['currencyCode'] = $this->currencyCode();
        $this->page['currencySymbol'] = $this->currencySymbol();
    }

    protected function detectSlideMode()
    {
        if (!$this->posUsername()) {
            return 'username';
        }

        if (!$this->posUser()) {
            return 'auth';
        }

        return 'amount';
    }

    public function posUsername()
    {
        return post('username', $this->param('username'));
    }

    public function currencyCode()
    {
        if (!$posUser = $this->posUser()) {
            return 'USD';
        }

        return $posUser->currency_code ?: 'USD';
    }

    public function currencySymbol()
    {
        if (!$posUser = $this->posUser()) {
            return '$';
        }

        return $posUser->currency_symbol ?: '$';
    }

    public function posUser()
    {
        if (!$user = $this->authManager->getUser()) {
            return null;
        }

        if (!$username = $this->posUsername()) {
            return null;
        }

        if ($user->pos_username != $username) {
            return null;
        }

        return $user;
    }

    public function user()
    {
        if (!$posUser = $this->posUser()) {
            return null;
        }

        return $posUser->user;
    }

    public function posWallet()
    {
        if (!$user = $this->user()) {
            return null;
        }

        return WalletModel::findActive($user);
    }

    public function onCheckUsername()
    {
        $username = post('username');

        if (!$username) {
            throw new ValidationException(['username' => 'The username field is required']);
        }

        $user = $this->authManager->findUserByLogin($username);

        if (!$user) {
            throw new ValidationException(['username' => 'Unable to find that account']);
        }

        return Redirect::to($this->currentPageUrl(['username' => $username]));
    }

    public function onCheckPin()
    {
        $pin = post('keypad_value');

        $username = $this->posUsername();

        $this->authManager->authenticate(['username' => $username, 'pin' => $pin]);

        Flash::success('Authentication successful');

        $this->prepareVars();
    }

    public function onRevertAmount()
    {
        $this->prepareVars();
    }

    public function onConfirmAmount()
    {
        $this->prepareVars();
        $this->setAmountsFromKeyPadValue();

        $this->page['screenMode'] = 'confirm';
    }

    public function onSubmitAmount()
    {
        $this->prepareVars();
        $this->setAmountsFromKeyPadValue();

        if (!$user = $this->user()) {
            throw new ApplicationException('Invalid user account');
        }

        $options = [
            'coinPrice' => $this->page['amountCoin'],
            'fiatPrice' => $this->page['amount'],
            'coinCurrency' => 'BCH',
            'fiatCurrency' => $this->currencyCode(),
        ];

        $sale = SaleModel::raiseSale($user, $options);

        $this->page['sale'] = $sale;
        $this->page['saleIndex'] = $sale->sale_index;
        $this->page['address'] = $sale->coin_address;
        $this->page['slideMode'] = 'transaction';
        $this->page['statusState'] = 'presend';
    }

    protected function setAmountsFromKeyPadValue()
    {
        $fiatAmount = post('keypad_value');
        $fiatAmount = $fiatAmount / 100;

        if (!$fiatAmount) {
            throw new ValidationException(['keypad_value' => 'No amount entered']);
        }

        $coinAmount = Currency::format($fiatAmount, [
            'from' => $this->currencyCode(),
            'to' => 'BCH',
            'decimals' => 8
        ]);

        $coinAmount = rtrim($coinAmount, '0');

        $this->page['amount'] = $fiatAmount;
        $this->page['amountCoin'] = $coinAmount;
    }

    public function onCheckPayment()
    {
        if (!$sale = $this->findSaleFromHash()) {
            throw new ApplicationException('Unable to find sale');
        }

        if (!$sale->checkBalance()) {
            $sale->touchFromUser();
        }

        $this->page['sale'] = $sale;
        $this->page['amountCoin'] = $this->formatNiceAmount($sale->coin_price);
        $this->page['balance'] = $this->formatNiceAmount($sale->coin_balance);
        $this->page['address'] = $sale->coin_address;
    }

    protected function findSaleFromHash($hash = null)
    {
        if (!$hash) {
            $hash = post('sale_hash');
        }

        if (!$user = $this->user()) {
            throw new ApplicationException('Invalid user account');
        }

        return SaleModel::applyUser($user)->where('hash', $hash)->first();
    }

    protected function formatNiceAmount($amount)
    {
        if (strpos($amount, '.') === false) {
            $amount .= '.0';
        }
        else {
            $amount = rtrim($amount, 0);

            if (substr($amount, -1) == '.') {
                $amount .= '0';
            }
        }

        return $amount;
    }

    public function onLaunchSalesNotes()
    {
        if (!$sale = $this->findSaleFromHash()) {
            throw new ApplicationException('Unable to find sale');
        }

        $this->page['sale'] = $sale;
    }

    public function onSaveSaleNotes()
    {
        if (!$sale = $this->findSaleFromHash()) {
            throw new ApplicationException('Unable to find sale');
        }

        $sale->notes = post('notes');
        $sale->save();

        Flash::success('Notes updated');
    }
}
