<?php

namespace App\Services\PaymentGateways;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use App\Models\PaymentPlans;
use App\Models\Setting;
use App\Models\SubscriptionItems;
use App\Models\UserOrder;
use App\Models\PaystackPaymentInfo;
use App\Models\Currency;
use App\Models\CustomSettings;
use App\Models\GatewayProducts;
use App\Models\Gateways;
use App\Models\OldGatewayProducts;
use App\Models\User;
use App\Models\UserAffiliate;
use App\Models\Coupon;
use App\Events\PaystackWebhookEvent;
use Laravel\Cashier\Subscription as Subscriptions;
use Carbon\Carbon;

/**
 * Base functions foreach payment gateway
 * @param saveAllProducts                                       || Used to generate new product id and price id of all saved membership plans in paypal gateway.
 * @param saveProduct ($plan)                                   || Saves Membership plan product in the gateway.
 * @param subscribe ($plan)                                     || Displays Payment Page of the gateway.
 * @param subscribeCheckout (Request $request, $referral= null) || -
 * @param prepaid ($plan)                                       || Displays Payment Page of the gateway for prepaid plans.
 * @param prepaidCheckout (Request $request, $referral= null)   || -
 * @param getSubscriptionStatus ($incomingUserId = null)        || 
 * @param getSubscriptionDaysLeft                               || 
 * @param subscribeCancel                                       || 
 * @param checkIfTrial                                          || 
 * @param getSubscriptionRenewDate                              || 
 * @param cancelSubscribedPlan ($subscription)                  || 
 */
class PaystackService 
{
    protected static $GATEWAY_CODE      = "paystack";
    protected static $GATEWAY_NAME      = "PayStack";
    protected static $client = "https://api.paystack.co/";
    protected static $product_endpoint = "product";
    protected static $plan_endpoint = "plan";
    protected static $subscription_endpoint = "subscription";
    protected static $transaction_verify_endpoint = "transaction/verify/";

    # payment functions
    # tested
    public static function saveAllProducts()
    {
        try{
            // Get all membership plans
            $plans = PaymentPlans::where('active', 1)->get();
            foreach ($plans as $plan) {
                self::saveProduct($plan);
            }
        }catch(\Exception $ex){
            error_log("paystack::saveAllProducts()\n".$ex->getMessage());
            return back()->with(['message' => $ex->getMessage(), 'type' => 'error']);
        }

    }
    # tested
    public static function saveProduct($plan){
        $gateway = Gateways::where("code", self::$GATEWAY_CODE)->where('is_active', 1)->first() ?? abort(404);
        try{
            #1 begain db transaction
            DB::beginTransaction();
            $currency = Currency::where('id', $gateway->currency)->first()->code;
            $taxValue = taxToVal($plan->price, $gateway->tax);
            $total = $plan->price + $taxValue;
            $price = (int)(((float)$total) * 100); // Must be in cents level for paystack
            $key = self::getKey($gateway);
            $oldProductId = null;
            #2 check if product exists. No->create, Yes->create->assign old to $oldProductId. (Create product in every situation. maybe user updated stripe credentials.)
            $product = GatewayProducts::where(["plan_id" => $plan->id, "gateway_code" => self::$GATEWAY_CODE])->first();
            
            $data = [
                'name' => $plan->name,
                'description' => $plan->name,
                'price' =>  $price == 0? 1000 : $price,
                'currency' => $currency,
            ];
            # Create product in every situation. maybe user updated paystack credentials.
            $newProduct =  self::curl_req(self::$product_endpoint, $key, $data);
            if($product != null){
                if($product->product_id != null){ 
                    $oldProductId = $product->product_id; # Product has been created before
                } # ELSE Product has not been created before but record exists. Create new product and update record.           
            }else{
                $product = new GatewayProducts();
                $product->plan_id = $plan->id;
                $product->gateway_code = self::$GATEWAY_CODE;
                $product->gateway_title = self::$GATEWAY_NAME;
            }
            $product->product_id = $newProduct['data']['product_code'];
            $product->plan_name = $plan->name;
            $product->save();
            # if not lifetime or free or onetime then create priceID
            if ($plan->price != 0 && $plan->type == "subscription" && $plan->frequency !== "lifetime_monthly" && $plan->frequency !== "lifetime_yearly") {
                $interval = $plan->frequency == "monthly" ? 'monthly' : 'annually';
                $billingPlan = self::curl_req(self::$plan_endpoint, $key, [
                    'name' => $plan->name,
                    'interval' => $interval, 
                    'amount' => $price,
                    'description' => $product->product_id,
                    'currency' => $currency,
                ]);
                if($product->price_id != null){
                    $history = new OldGatewayProducts();
                    $history->plan_id = $plan->id;
                    $history->plan_name = $plan->name;
                    $history->gateway_code = self::$GATEWAY_CODE;
                    $history->product_id = $product->product_id;
                    $history->old_product_id = $oldProductId;
                    $history->old_price_id = $product->price_id;
                    $history->new_price_id = $billingPlan['data']['plan_code'];
                    $history->status = 'check';
                    $history->save();
                    $tmp = self::updateUserData();
                }
                $product->price_id = $billingPlan['data']['plan_code'];
            }else{
                $product->price_id = "Not Needed";
            }
            $product->save();
            DB::commit();
        }catch(\Exception $ex){
            DB::rollBack();
            Log::error(self::$GATEWAY_CODE."-> saveProduct():\n" . $ex->getMessage());
            return back()->with(['message' => $ex->getMessage(), 'type' => 'error']);
        }
    } 
    # tested
    public static function subscribe($plan)
    {
        $gateway = Gateways::where("code", self::$GATEWAY_CODE)->where('is_active', 1)->first() ?? abort(404);
        try {
            DB::beginTransaction();
            $planId = $plan->id;
            $settings = Setting::first();
            $exception = null;
            $orderId = Str::random(12);
            $currency = Currency::where('id', $gateway->currency)->first()->code;
            $key = self::getKey($gateway);
            $user = auth()->user();
            $taxRate  = $gateway->tax;
            $taxValue = taxToVal($plan->price, $taxRate);
            $coupon = checkCouponInRequest(); #if there a coupon in request it will return the coupin instanse
            
            $productId = self::getPaystackProductId($plan->id);
            $billingPlanId = self::getPaystackPriceId($plan->id);

            if($productId == null){
                $exception = __("Product ID is not set! Please save Membership Plan again.");
                return back()->with(['message' => $exception,'type' => 'error' ]);
            }
            if ($billingPlanId == null) {
                $exception = __("Product Price ID is not set! Please save Membership Plan again.");
                return back()->with(['message' => $exception,'type' => 'error' ]);
            }
            $newDiscountedPrice = $plan->price + $taxValue; # total with tax
            if($coupon && $plan->price != 0){
                $newDiscountedPrice  = $plan->price - ($plan->price * ($coupon->discount / 100));
                if ($newDiscountedPrice != floor($newDiscountedPrice)) {
                    $newDiscountedPrice = number_format($newDiscountedPrice, 2);
                }
                if ($plan->price != 0 && $plan->type == "subscription" && $plan->frequency !== "lifetime_monthly" && $plan->frequency !== "lifetime_yearly") {
                    $interval = $plan->frequency == "monthly" ?'monthly' : 'annually';
                    $billingPlan = self::curl_req(self::$plan_endpoint, $key, [
                        'name' => "discount_item_" . time(),
                        'interval' => $interval, 
                        'amount' => (int)(((float)$newDiscountedPrice) * 100),
                        'description' => "coupon_". $coupon->code . "_user_" . $user->id . "_plan_" . $plan->id ,
                        'currency' => $currency,
                    ]);
                    $billingPlanId = $billingPlan['data']['plan_code'];
                }
            }            
            $payment = new UserOrder();
            $payment->order_id = $orderId;
            $payment->plan_id = $plan->id;
            $payment->user_id = $user->id;
            $payment->payment_type = self::$GATEWAY_CODE;
            $payment->price = $newDiscountedPrice;
            $payment->affiliate_earnings = ($newDiscountedPrice*$settings->affiliate_commission_percentage)/100;
            $payment->status = 'Waiting';
            $payment->country = $user->country ?? 'Unknown';
            $payment->save();
        }
        catch (\Exception $ex) {
            DB::rollBack();
            Log::error(self::$GATEWAY_CODE."-> subscribe(): ". $ex->getMessage());
            return back()->with(['message' => Str::before($ex->getMessage(), ':'),'type' => 'error' ]);
        }    
        return view("panel.user.finance.subscription.". self::$GATEWAY_CODE, compact('plan','taxRate','taxValue','newDiscountedPrice', 'billingPlanId', 'exception', 'orderId', 'productId', 'gateway', 'planId'));
    }



    public function subscribeCheckout(Request $request, $referral= null)
    {
        try{
            $previousRequest = app('request')->create(url()->previous());

            $gateway = Gateways::where("code", "paystack")->first();
            if ($gateway == null) {
                abort(404);
            }

            $orderId = $request->orderId;
            $productId = $request->productId;
            $planId = $request->planId;
            $billingPlanId = $request->billingPlanId;

            $payment_response = json_decode($request->response, true);
            $payment_response_status = $payment_response['status'];
            $payment_response_message = $payment_response['message'];
            $payment_response_reference = $payment_response['reference'];


            $plan = PaymentPlans::where('id', $planId)->first();
            $payment = UserOrder::where('order_id', $orderId)->first();
            $user = Auth::user();

            if ($gateway->mode == 'sandbox') {
                $key = $gateway->sandbox_client_secret;
            } else {
                $key = $gateway->live_client_secret;
            }

            # verify transaction with paystack if it was successful then continue
            $reqs = self::curl_req_get(self::$transaction_verify_endpoint. $payment_response_reference , $key);

            if($reqs['status'] == false){ # if something went wrong with the request
                abort(404);
            }
            # failed or success
            if($reqs['data']['status'] != 'success'){ # if the transaction was not successful
                abort(400 , $reqs['data']['gateway_response']);
            }

            $bill_customer_id = $reqs['data']['customer']['id'];
            $bill_plan_id = $reqs['data']['plan_object']['id'];


            # log the transaction data to database
            $info = new PaystackPaymentInfo();
            $info->user_id = Auth::user()->id;
            $info->email = Auth::user()->email;
            $info->reference = $payment_response['reference'] ?? '';
            $info->trans = $payment_response['trans'] ?? '';
            $info->status = $payment_response['status']?? '';
            $info->message = $payment_response['message']?? '';
            $info->transaction = $payment_response['transaction']?? '';
            $info->trxref = $payment_response['trxref']?? '';
            $info->amount = ($reqs['data']['amount'] / 100) ?? '';
            $info->customer_code = $reqs['data']['customer']['customer_code']?? '';
            $info->plan_code = ($reqs['data']['plan']?? ''). " / " . $planId;
            $info->currency = $reqs['data']['currency']?? '';
            $info->other = $reqs['data']['paidAt']?? '';
            $info->save();

            $subscription_billing = self::curl_req_get(self::$subscription_endpoint. "?customer=".$bill_customer_id."&plan=".$bill_plan_id , $key);
            if($subscription_billing['status'] == false){ # if something went wrong with the request
                abort(404);
            }
            $subscription_billing_code = $subscription_billing['data'][0]['subscription_code'];

            if($payment != null){

                if ($previousRequest->has('coupon')) {
                    $coupon = Coupon::where('code', $previousRequest->input('coupon'))->first();
                    if($coupon){
                        $coupon->usersUsed()->attach(auth()->user()->id);
                    }
                }


                $subscription = new SubscriptionsModel();
                $subscription->user_id = $user->id;
                $subscription->name = $planId;
                $subscription->stripe_id = $subscription_billing_code;
                $subscription->stripe_status = 'active';
                $subscription->stripe_price = $billingPlanId;
                $subscription->quantity = 1;
                $subscription->plan_id = $planId;
                $subscription->paid_with = 'paystack';
                $subscription->save();


                $subscriptionItem = new SubscriptionItems();
                $subscriptionItem->subscription_id = $subscription->id;
                $subscriptionItem->stripe_id = $orderId;
                $subscriptionItem->stripe_product = $productId;
                $subscriptionItem->stripe_price = $billingPlanId;
                $subscriptionItem->quantity = 1;
                $subscriptionItem->save();

                $payment->status = 'Success';
                $payment->save();

                $plan->total_words == -1? ($user->remaining_words = -1) : ($user->remaining_words += $plan->total_words);
                $plan->total_images == -1? ($user->remaining_images = -1) : ($user->remaining_images += $plan->total_images);

                $user->save();

                createActivity($user->id, __('Subscribed'), $plan->name.' '. __('Plan'), null);

                return redirect()->route('dashboard.'.auth()->user()->type.'.index')->with(['message' => 'Thank you for your purchase. Enjoy your remaining words and images.', 'type' => 'success']);

            }else{
                $msg="PaystackController::subscribePay(): Could not find required payment order!";
                error_log($msg);
                return redirect()->route('dashboard.'.auth()->user()->type.'.index')->with(['message' => $msg, 'type' => 'error']);
            }

        } catch (\Exception $th) {
            error_log("PaystackController::subscribePay(): ".$th->getMessage());
            return redirect()->route('dashboard.'.auth()->user()->type.'.index')->with(['message' => $th->getMessage(), 'type' => 'error']);
        }
    }
    public static function subscribeCancel(){


        $user = Auth::user();
        $userId=$user->id;
        // Get current active subscription
        $activeSub = SubscriptionsModel::where([['stripe_status', '=', 'active'], ['user_id', '=', $userId], ['paid_with', '=', 'paystack']])->orWhere([['stripe_status', '=', 'trialing'], ['user_id', '=', $userId], ['paid_with', '=', 'paystack']])->first();

        if($activeSub != null){

            $plan = PaymentPlans::where('id', $activeSub->plan_id)->first();

            $gateway = Gateways::where("code", "paystack")->first();
            if($gateway == null) { abort(404); } 
            if ($gateway->mode == 'sandbox') {
                $key = $gateway->sandbox_client_secret;
            } else {
                $key = $gateway->live_client_secret;
            }

            $reqs = self::curl_req_get(self::$subscription_endpoint. "/" . $activeSub->stripe_id , $key);
            if($reqs['status'] == false){ # if something went wrong with the request
                abort(404, $reqs['message']);
            }
            $mailToken = $reqs['data']['email_token'];


            $request = self::curl_req(self::$subscription_endpoint."/disable", $key, [
                'code' => $activeSub->stripe_id,
                'token' => $mailToken,
            ]);

            if($request['status'] == true && $request['message'] == "Subscription disabled successfully"){

                $activeSub->stripe_status = "cancelled";
                $activeSub->ends_at = \Carbon\Carbon::now();
                $activeSub->save();

                $recent_words = $user->remaining_words - $plan->total_words;
                $recent_images = $user->remaining_images - $plan->total_images;


                $user->remaining_words = $recent_words < 0 ? 0 : $recent_words;
                $user->remaining_images = $recent_images < 0 ? 0 : $recent_images;
                $user->save();

                createActivity($user->id, 'Cancelled', 'Subscription plan', null);

                return back()->with(['message' => 'Your subscription is cancelled succesfully.', 'type' => 'success']);

            }
            else{
                error_log("PaystackController::disableOldSubscriptionAndReturnNew(): ".$request['message']);
                return back()->with(['message' => 'Your subscription could not cancelled.', 'type' => 'error']);
            }          


        }

        return back()->with(['message' => 'Could not find active subscription. Nothing changed!', 'type' => 'error']);
    }
    public static function prepaid($planId, $plan, $incomingException = null){

        $newDiscountedPrice = $plan->price;

        $couponCode = request()->input('coupon');
        if($couponCode){
            $coupone = Coupon::where('code', $couponCode)->first();
            if($coupone){
                $newDiscountedPrice  = $plan->price - ($plan->price * ($coupone->discount / 100));   
                if ($newDiscountedPrice != floor($newDiscountedPrice)) {
                    $newDiscountedPrice = number_format($newDiscountedPrice, 2);
                }
            }
        }
        
        $gateway = Gateways::where("code", "paystack")->first();
        if($gateway == null) { abort(404); } 
        $currency = Currency::where('id', $gateway->currency)->first()->code;
        $orderId = null;
        $exception = $incomingException;

        try {
            if(self::getPaystackProductId($planId) == null){
                $exception = "Product ID is not set! Please save Membership Plan again.";
            }   
        } catch (\Exception $th) {
            $exception = Str::before($th->getMessage(),':');
        }
        return view('panel.user.payment.prepaid.payWithPaystack', compact('plan', 'newDiscountedPrice','orderId', 'gateway', 'exception', 'currency', 'planId'));
    }
    public function prepaidCheckout(Request $request)
    {
        $previousRequest = app('request')->create(url()->previous());

        $payment_response = json_decode($request->response, true);
        $payment_response_reference = $payment_response['reference'];

        $gateway = Gateways::where("code", "paystack")->first();
        if ($gateway == null) {
            abort(404);
        }
        if ($gateway->mode == 'sandbox') {
            $key = $gateway->sandbox_client_secret;
        } else {
            $key = $gateway->live_client_secret;
        }

        # verify transaction with paystack if it was successful then continue
        $reqs = self::curl_req_get(self::$transaction_verify_endpoint. $payment_response_reference , $key);
        if($reqs['status'] == false){ # if something went wrong with the request
            abort(404);
        }
        # failed or success
        if($reqs['data']['status'] != 'success'){ # if the transaction was not successful
            abort(400 , $reqs['data']['gateway_response']);
        }




        # log the transaction data to database
        $info = new PaystackPaymentInfo();
        $info->user_id = Auth::user()->id;
        $info->email = Auth::user()->email;
        $info->reference = $payment_response['reference'] ?? '';
        $info->trans = $payment_response['trans'] ?? '';
        $info->status = $payment_response['status']?? '';
        $info->message = $payment_response['message']?? '';
        $info->transaction = $payment_response['transaction']?? '';
        $info->trxref = $payment_response['trxref']?? '';

        $info->amount = ($reqs['data']['amount'] / 100) ?? '';
        $info->customer_code = $reqs['data']['customer']['customer_code']?? '';
        $info->plan_code = ($reqs['data']['plan']?? ''). " / " . $request->plan;
        $info->currency = $reqs['data']['currency']?? '';
        $info->other = $reqs['data']['paidAt']?? '';
        $info->save();

        $plan = PaymentPlans::find($request->plan);
        $user = Auth::user();
        $settings = Setting::first();


        $newDiscountedPrice = $plan->price;
        if ($previousRequest->has('coupon')) {
            $coupon = Coupon::where('code', $previousRequest->input('coupon'))->first();
            if($coupon){
                $newDiscountedPrice = $plan->price - ($plan->price * ($coupon->discount / 100));
                $coupon->usersUsed()->attach(auth()->user()->id);
            }
        }


        $payment = new UserOrder();
        $payment->order_id = Str::random(12);
        $payment->plan_id = $plan->id;
        $payment->type = 'prepaid';
        $payment->user_id = $user->id;
        $payment->payment_type = 'Credit, Debit Card';
        $payment->price = $newDiscountedPrice;
        $payment->affiliate_earnings = ($newDiscountedPrice * $settings->affiliate_commission_percentage) / 100;
        $payment->status = 'Success';
        $payment->country = $user->country ?? 'Unknown';
        $payment->save();

        $plan->total_words == -1? ($user->remaining_words = -1) : ($user->remaining_words += $plan->total_words);
        $plan->total_images == -1? ($user->remaining_images = -1) : ($user->remaining_images += $plan->total_images);
        $user->save();

        createActivity($user->id, __('Purchased'), $plan->name . ' '. __('Token Pack'), null);

        return redirect()->route('dashboard.'.auth()->user()->type.'.index')->with(['message' => __('Thank you for your purchase. Enjoy your remaining words and images.'), 'type' => 'success']);
    }
    public static function getSubscriptionDaysLeft(){

        // $gateway = Gateways::where("code", "paystack")->first();
        // if ($gateway == null) {
        //     return null;
        // }

        // if ($gateway->mode == 'sandbox') {
        //     $key = $gateway->sandbox_client_secret;
        // } else {
        //     $key = $gateway->live_client_secret;
        // }

        // $userId=Auth::user()->id;
        // // Get current active subscription
        // $activeSub = SubscriptionsModel::where([['stripe_status', '=', 'active'], ['user_id', '=', $userId], ['paid_with', '=', 'paystack']])->orWhere([['stripe_status', '=', 'trialing'], ['user_id', '=', $userId], ['paid_with', '=', 'paystack']])->first();
        // if($activeSub != null){
        //     $reqs = self::curl_req_get(self::$subscription_endpoint. "/" .$activeSub->stripe_id , $key);
        //     if($reqs['status'] == false){ # if something went wrong with the request
        //         error_log("PaypalController::getSubscriptionStatus() :\n".json_encode($subscription));
        //         return null;
        //     }
        //     //if user is in trial
        //     # TODO: add trail logic here later if possible
        // }

        $gateway = Gateways::where("code", "paystack")->first();
        if ($gateway == null) {
            return null;
        }

        if ($gateway->mode == 'sandbox') {
            $key = $gateway->sandbox_client_secret;
        } else {
            $key = $gateway->live_client_secret;
        }

        $userId=Auth::user()->id;
        // Get current active subscription
        $activeSub = SubscriptionsModel::where([['stripe_status', '=', 'active'], ['user_id', '=', $userId], ['paid_with', '=', 'paystack']])->orWhere([['stripe_status', '=', 'trialing'], ['user_id', '=', $userId], ['paid_with', '=', 'paystack']])->first();
        if($activeSub != null){
            $reqs = self::curl_req_get(self::$subscription_endpoint. "/" .$activeSub->stripe_id , $key);
            if($reqs['status'] == false){ # if something went wrong with the request
                error_log("PaystackController::getSubscriptionRenewDate() :\n".json_encode($reqs));
                return back()->with(['message' => 'Paystack Gateway : '.json_encode($reqs), 'type' => 'error']);
            }
            if(isset($reqs['data']['next_payment_date'])){
                // return \Carbon\Carbon::parse($reqs['data']['next_payment_date'])->format('F jS, Y');
                return \Carbon\Carbon::now()->diffInDays($reqs['data']['next_payment_date']);
            }
        }
        return null;
    }
    public static function getSubscriptionRenewDate(){ 
        $gateway = Gateways::where("code", "paystack")->first();
        if ($gateway == null) {
            return null;
        }

        if ($gateway->mode == 'sandbox') {
            $key = $gateway->sandbox_client_secret;
        } else {
            $key = $gateway->live_client_secret;
        }

        $userId=Auth::user()->id;
        // Get current active subscription
        $activeSub = SubscriptionsModel::where([['stripe_status', '=', 'active'], ['user_id', '=', $userId], ['paid_with', '=', 'paystack']])->orWhere([['stripe_status', '=', 'trialing'], ['user_id', '=', $userId], ['paid_with', '=', 'paystack']])->first();
        if($activeSub != null){
            $reqs = self::curl_req_get(self::$subscription_endpoint. "/" .$activeSub->stripe_id , $key);
            if($reqs['status'] == false){ # if something went wrong with the request
                error_log("PaystackController::getSubscriptionRenewDate() :\n".json_encode($reqs));
                return back()->with(['message' => 'Paystack Gateway : '.json_encode($reqs), 'type' => 'error']);
            }
            if(isset($reqs['data']['next_payment_date'])){
                return \Carbon\Carbon::parse($reqs['data']['next_payment_date'])->format('F jS, Y');
            }else{
                $activeSub->stripe_status = "cancelled";
                $activeSub->ends_at = \Carbon\Carbon::now();
                $activeSub->save();
                return \Carbon\Carbon::now()->format('F jS, Y');
            }
        }
        return null;
    }
    public static function checkIfTrial(){
        // $gateway = Gateways::where("code", "paystack")->first();
        // if ($gateway == null) {
        //     return null;
        // }

        // if ($gateway->mode == 'sandbox') {
        //     $key = $gateway->sandbox_client_secret;
        // } else {
        //     $key = $gateway->live_client_secret;
        // }

        // $userId=Auth::user()->id;
        // // Get current active subscription
        // $activeSub = SubscriptionsModel::where([['stripe_status', '=', 'active'], ['user_id', '=', $userId], ['paid_with', '=', 'paystack']])->orWhere([['stripe_status', '=', 'trialing'], ['user_id', '=', $userId], ['paid_with', '=', 'paystack']])->first();
        // if($activeSub != null){
        //     $reqs = self::curl_req_get(self::$subscription_endpoint. "/" .$activeSub->stripe_id , $key);
        //     if($reqs['status'] == false){ # if something went wrong with the request
        //         error_log("PaystackController::checkIfTrial() :\n".json_encode($reqs));
        //         return back()->with(['message' => 'Paystack Gateway : '.json_encode($reqs), 'type' => 'error']);
        //     }
        //     # TODO: check if trail logic here later if possible
        // }
        return false;
    }
    public static function cancelSubscribedPlan($planId, $subsId){

        $user = Auth::user();
        $currentSubscription = SubscriptionsModel::where('id', $subsId)->first();

        if($currentSubscription != null){

            $plan = PaymentPlans::where('id', $planId)->first();
            $gateway = Gateways::where("code", "paystack")->first();
            if ($gateway == null) {
                return null;
            }

            if ($gateway->mode == 'sandbox') {
                $key = $gateway->sandbox_client_secret;
            } else {
                $key = $gateway->live_client_secret;
            }

            $get_subscribe_info = self::curl_req_get(self::$cancelSubscribedPlan. "/" .$currentSubscription->stripe_id , $key);
            if($get_subscribe_info['status'] == false){ # if something went wrong with the request
                error_log("PaystackController::cancelSubscribedPlan() :\n".json_encode($get_subscribe_info));
                return back()->with(['message' => 'Paystack Gateway : '.json_encode($get_subscribe_info), 'type' => 'error']);
            }

            $request = self::curl_req(self::$subscription_endpoint."/disable", $key, [
                'code' => $currentSubscription->stripe_id,
                'token' => $get_subscribe_info['data']['email_token'],
            ]);

            if($request['status'] == true && $request['message'] == "Subscription disabled successfully"){

                $currentSubscription->stripe_status = "cancelled";
                $currentSubscription->ends_at = \Carbon\Carbon::now();
                $currentSubscription->save();
                return true;

            }
        }

        return false;
    }




    # tested
    private static function isValidWebhookSignature($input, $secret, $signature)
    {
        $expectedSignature = hash_hmac('sha512', $input, $secret);
        return hash_equals($expectedSignature, $signature);
    }
    # tested
    public static function handleWebhook(Request $request)
    {
        $input  = $request->getContent();
        $secret = self::getKey(); 
        // Validate the Paystack webhook signature
        if (self::isValidWebhookSignature($input, $secret, $request->header('HTTP_X_PAYSTACK_SIGNATURE'))) {
            // Handle the webhook events
            $payload = json_decode($input, true);
            event(new PaystackWebhookEvent($payload));
        }
        // Invalid signature
        return response()->json(['status' => 'error'], 400);
    }
    # tested
    /**
     * curl post request template
     */
    public static function curl_req($second_url = "" , $key, $data = [])
    {
        $fields_string = http_build_query($data);
        //open connection
        $ch = curl_init();
        //set the url, number of POST vars, POST data
        curl_setopt($ch,CURLOPT_URL, self::$client . $second_url);
        curl_setopt($ch,CURLOPT_POST, true);
        curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer ". $key,
            "Cache-Control: no-cache",
        ));
        //So that curl_exec returns the contents of the cURL; rather than echoing it
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, true); 
        //execute post
        $request = curl_exec($ch);
        curl_close($ch);
        if ($request) {
            $result = json_decode($request, true);
            if (isset($result['status']) && $result['status'] !== true) {
                abort(400, "Paystack: ".$result['message']);
            } 
            return $result;
        } else {
            abort(400, $result);
        }
    }
    # tested
    /**
     * curl get request template
     */
    public static function curl_req_get($param ,$key)
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
          CURLOPT_URL => self::$client.$param,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => "",
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 30,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => "GET",
          CURLOPT_HTTPHEADER => array(
            "Authorization: Bearer ".$key,
            "Cache-Control: no-cache",
          ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        if ($err) {
            abort(400, "Paystack: ".$err);
        } else {
            $result = json_decode($response, true);
            if (isset($result['status']) && $result['status'] !== true) {
                abort(400, "Paystack: ".$result['message']);
            } 
            return $result;            
        }
    }
    # tested
    /**
     * Reads GatewayProducts table and returns price id of the given plan
     */
    public static function getPaystackPriceId($planId){

        //check if plan exists
        $plan = PaymentPlans::where('id', $planId)->first();
        if($plan != null){
            $product = GatewayProducts::where(["plan_id" => $planId, "gateway_code" => "paystack"])->first();
            if($product != null){
                return $product->price_id;
            }else{
                return null;
            }
        }
        return null;
    }
    # tested
    /**
     * Reads GatewayProducts table and returns price id of the given plan
     */
    public static function getPaystackProductId($planId){

        //check if plan exists
        $plan = PaymentPlans::where('id', $planId)->first();
        if($plan != null){
            $product = GatewayProducts::where(["plan_id" => $planId, "gateway_code" => "paystack"])->first();
            if($product != null){
                return $product->product_id;
            }else{
                return null;
            }
        }
        return null;
    }
    # tested
    /**
     * get key if sadbox or live
     */
    private static function getKey($gateway = null) {
        $theGateway = $gateway ?? Gateways::where("code", self::$GATEWAY_CODE)->where('is_active', 1)->first() ?? abort(404);
        if ($theGateway->mode == 'sandbox') {
            $key = $theGateway->sandbox_client_secret;
        } else {
            $key = $theGateway->live_client_secret;
        }
        return $key;
    }
    # tested
    /**
     * Since price id (billing plan) is changed, we must update user data, i.e cancel current subscriptions.
     */
    public static function updateUserData(){
        $key = self::getKey();
        try {
            $history = OldGatewayProducts::where(["gateway_code" => self::$GATEWAY_CODE, "status" => 'check'])->get();
            if($history != null){
                foreach ($history as $record) {
                    // check record current status from gateway
                    $lookingFor = $record->old_price_id; // billingPlan id in paystack 
                    # get also subscription id and customer id and mail token 
                    // search subscriptions for record
                    $subs = Subscriptions::where('paid_with', self::$GATEWAY_CODE)
                    ->where('stripe_status', 'active')
                    ->where('stripe_price', $lookingFor)
                    ->get();
                    foreach ($subs ?? [] as $sub) {
                        $subscriptionId = $sub->stripe_id;
                        $reqs = self::curl_req_get(self::$subscription_endpoint. "/" . $subscriptionId , $key);
                        if($reqs['status'] == false){ # if something went wrong with the request
                            abort(404);
                        }
                        $mailToken = $reqs['data']['email_token'];
                        $customerId = $reqs['data']['customer']['customer_code'];
                        $planId = $reqs['data']['plan']['plan_code'];
                        // cancel old subscription from gateway
                        $new_subscription_code = self::disableOldSubscriptionAndReturnNew($subscriptionId, $mailToken, $customerId, $planId);
                        if($new_subscription_code == false){
                            error_log("PaystackService::updateUserData(): Could not create new subscription for user: ".$sub->user_id);
                            continue;
                        }
                        $sub->stripe_id = $new_subscription_code;
                        $sub->save();
                    }
                    $record->status = 'checked';
                    $record->save();
                }
            }
        } catch (\Exception $ex) {
            Log::error(self::$GATEWAY_CODE."-> updateUserData():\n" . $ex->getMessage());
            return ["result" => Str::before($ex->getMessage(), ':')];
        }


    

    }
    # tested
    public static function disableOldSubscriptionAndReturnNew($subscriptionId, $mail_token, $customerID, $planID){
        $key = self::getKey();
        $request = self::curl_req(self::$subscription_endpoint."/disable", $key, [
            'code' => $subscriptionId,
            'token' => $mail_token,
        ]);
        if($request['status'] == true && $request['message'] == "Subscription disabled successfully"){
            # create new subscription insted of old one
            $req = self::curl_req(self::$subscription_endpoint, $key, [
                'customer' => $customerID,
                'plan' => $planID,
            ]);
            if($req['status'] == false){
                error_log("PaystackService::disableOldSubscriptionAndReturnNew(): ".$req['message']);
                return false;
            }
            return $req['data']['subscription_code'];          
        }
        else{
            error_log("PaystackService::disableOldSubscriptionAndReturnNew(): ".$request['message']);
            return false;
        }
    }
}