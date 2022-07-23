<?php

namespace App\CentralLogics;


use App\Model\Order;
use App\Model\Product;
use App\Model\TimeSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class OrderLogic
{
    public static function availability($request)
    {
        $_booking=  DB::table('orders')
        ->join('branches', 'branches.id', '=', 'orders.branch_id')
        ->where('orders.delivery_date',$request->date)
        ->where('orders.order_type','buffet')
        ->where('delivery_time',$request->session)
        ->where('branches.id',$request->branch_id)
        ->groupBy('orders.delivery_time')
        ->havingRaw('SUM(orders.capacity) > branches.capacity')
        ->selectRaw('SUM(orders.capacity) as alloted, branches.capacity as available')
        ->first();

        return isset($_booking)&&$_booking->alloted < $_booking->available || !isset($_booking); 
    }

    public static function slots($request)
    {
        // $branch = Branch::find($request->branch_id);
        // $restaurant_open_time = BusinessSetting::where(['key' => 'restaurant_open_time'])->first()->value;
        // $restaurant_close_time = BusinessSetting::where(['key' => 'restaurant_close_time'])->first()->value;
        $day = date('w',strtotime($request->date));
        $slots = [];
        $schedule = TimeSchedule::select('day', 'opening_time', 'closing_time')->where('day',$day)->first();
        if(isset($schedule))
        {
            $start = strtotime($schedule->opening_time);
            while($start < strtotime($schedule->opening_time))
            {
                $slot =date('h:i A',$start); 
                $_booking=  DB::table('orders')
                ->join('branches', 'branches.id', '=', 'orders.branch_id')
                ->where('orders.delivery_date',$request->date)
                ->where('orders.order_type','buffet')
                ->where('delivery_time',$slot)
                ->where('branches.id',$request->branch_id)
                ->selectRaw('SUM(orders.capacity) as alloted, branches.capacity as available')
                ->first();
                if(isset($_booking)&&$_booking->alloted < $_booking->available || !isset($_booking))
                $slots[] = $slot;
                $start = $start+(30*60);
            }
        }

        return $slots; 
    }
    


    public static function track_order($order_id)
    {
        return Helpers::order_data_formatting(Order::with(['details', 'delivery_man.rating'])->where(['id' => $order_id])->first(), false);
    }

    public static function place_order($customer_id, $email, $customer_info, $cart, $payment_method, $discount, $coupon_code = null)
    {
        try {
            $or = [
                'id' => 100000 + Order::all()->count() + 1,
                'user_id' => $customer_id,
                'order_amount' => CartManager::cart_grand_total($cart) - $discount,
                'payment_status' => 'unpaid',
                'order_status' => 'pending',
                'payment_method' => $payment_method,
                'transaction_ref' => null,
                'discount_amount' => $discount,
                'coupon_code' => $coupon_code,
                'discount_type' => $discount == 0 ? null : 'coupon_discount',
                'shipping_address' => $customer_info['address_id'],
                'created_at' => now(),
                'updated_at' => now()
            ];

            $o_id = DB::table('orders')->insertGetId($or);

            foreach ($cart as $c) {
                $product = Product::where('id', $c['id'])->first();
                $or_d = [
                    'order_id' => $o_id,
                    'product_id' => $c['id'],
                    'seller_id' => $product->added_by == 'seller' ? $product->user_id : '0',
                    'product_details' => $product,
                    'qty' => $c['quantity'],
                    'price' => $c['price'],
                    'tax' => $c['tax'] * $c['quantity'],
                    'discount' => $c['discount'] * $c['quantity'],
                    'discount_type' => 'discount_on_product',
                    'variant' => $c['variant'],
                    'variation' => json_encode($c['variations']),
                    'delivery_status' => 'pending',
                    'shipping_method_id' => $c['shipping_method_id'],
                    'payment_status' => 'unpaid',
                    'created_at' => now(),
                    'updated_at' => now()
                ];
                DB::table('order_details')->insert($or_d);
            }

            $emailServices = Helpers::get_business_settings('mail_config');
            if (isset($emailServices['status']) && $emailServices['status'] == 1) {
                Mail::to($email)->send(new \App\Mail\OrderPlaced($o_id));
            }

        } catch (\Exception $e) {

        }

        return $o_id;
    }
}
