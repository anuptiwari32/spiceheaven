<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\CentralLogics\OrderLogic;
use App\Http\Controllers\Controller;
use App\Model\Order;
use App\Model\Product;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class BookATableController extends Controller
{

    public function check_availability(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'branch_id' => 'required',
                'session' => 'required',
                'date' => 'required'
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }
                return response()->json(OrderLogic::availability($request), 200);      
            } catch (\Throwable $th) {
                return response()->json([$th], 403);

            //throw $th;
        }
    }

    public function check_slots(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'branch_id' => 'required',
                'session' => 'required',
                'date' => 'required'
            ]);
    
            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }
                return response()->json(OrderLogic::slots($request), 200);      
            } catch (\Throwable $th) {
                return response()->json([$th], 403);

            //throw $th;
        }
    }
    
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create( Request $request)
    {
        
        $validator = Validator::make($request->all(), [
            'order_amount' => 'required',
            'delivery_address_id' => 'required',
            'order_type' => 'required',
            'branch_id' => 'required',
            'delivery_time' => 'required',
            'delivery_date' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        //order scheduling
        if ($request['delivery_time'] == 'now') {
            $del_date = Carbon::now()->format('Y-m-d');
            $del_time = Carbon::now()->format('H:i:s');
        } else {
            $del_date = $request['delivery_date'];
            $del_time = $request['delivery_time'];
        }

        try {
            $or = [
                'id' => 100000 + Order::all()->count() + 1,
                'user_id' => $request->user()->id,
                'order_amount' => Helpers::set_price($request['order_amount']),
                'coupon_discount_amount' => Helpers::set_price($request->coupon_discount_amount),
                'coupon_discount_title' => $request->coupon_discount_title == 0 ? null : 'coupon_discount_title',
                'payment_status' => 'unpaid',
                'order_status' => 'confirmed',
                'coupon_code' => $request['coupon_code'],
                'payment_method' => $request->payment_method,
                'transaction_reference' => $request->transaction_reference ?? null,
                'order_note' => $request['order_note'],
                'order_type' => $request['order_type'],
                'branch_id' => $request['branch_id'],
                'delivery_address_id' => null,
                'delivery_date' => $del_date,
                'delivery_time' => $del_time,
                'delivery_address' => json_encode(null),
                'delivery_charge' => 0,
                'capacity'=>$request->capacity,
                'preparation_time' =>  0,
                'created_at' => now(),
                'updated_at' => now()
            ];

            $o_id = DB::table('orders')->insertGetId($or);

            foreach ($request['cart'] as $c) {
                $product = Product::find($c['product_id']);
                if (array_key_exists('variation', $c) && count(json_decode($product['variations'], true)) > 0) {
                    $price = Helpers::variation_price($product, json_encode($c['variation']));
                } else {
                    $price = Helpers::set_price($product['price']);
                }
                //                $capacity += $c['quantity'];

                $or_d = [
                    'order_id' => $o_id,
                    'product_id' => $c['product_id'],
                    'product_details' => $product,
                    'quantity' => $c['quantity'],
                    'price' => $price,
                    'tax_amount' => Helpers::tax_calculate($product, $price),
                    'discount_on_product' => Helpers::discount_calculate($product, $price),
                    'discount_type' => 'discount_on_product',
                    'variant' => json_encode($c['variant']),
                    'variation' => array_key_exists('variation', $c) ? json_encode($c['variation']) : json_encode([]),
                    'add_on_ids' => json_encode($c['add_on_ids']),
                    'add_on_qtys' => json_encode($c['add_on_qtys']),
                    'created_at' => now(),
                    'updated_at' => now()
                ];

                DB::table('order_details')->insert($or_d);

                //update product popularity point
                Product::find($c['product_id'])->increment('popularity_count');
            }

            $fcm_token = $request->user()->cm_firebase_token;
            $value = Helpers::order_status_update_message('pending');
            try {
                //send push notification
                if ($value) {
                    $data = [
                        'title' => translate('Order'),
                        'description' => $value,
                        'order_id' => $o_id,
                        'image' => '',
                        'type'=>'order_status',
                    ];
                    Helpers::send_push_notif_to_device($fcm_token, $data);
                }

                //send email
                $emailServices = Helpers::get_business_settings('mail_config');
                if (isset($emailServices['status']) && $emailServices['status'] == 1) {
                    Mail::to($request->user()->email)->send(new \App\Mail\OrderPlaced($o_id));
                }

            } catch (\Exception $e) {

            }

            return response()->json([
                'message' => translate('booking_success'),
                'order_id' => $o_id
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([$e], 403);
        }

    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
