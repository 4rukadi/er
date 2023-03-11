<?php

namespace App\Http\Controllers\Member;

use Auth;
use Tripay;
use App\Models\Post;
use App\Models\Invoice;
use Illuminate\Http\Request;
use App\Enums\PaymentStatusState;
use App\Libraries\TripayOrderItem;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Libraries\TripayTransaction;
use Illuminate\Support\Str;

class BillingController extends Controller
{
    public function index()
    {
        if (Auth::user()->balance < 0) {
            alert('Account Suspended', 'warning', __('Hub Admin Now : t.me/riyan99s !!!'));
            return redirect()->route('member.dashboard');
        }
        
        $waitingPayments = Auth::user()->invoices()
            ->notPrivate()
            ->skipExpired()
            ->withSum('invoiceItems as total_amount', 'total_price')
            ->where('type', 'TOPUP')
            ->where('status', 'UNPAID')
            ->orderBy('created_at', 'desc')
            ->get();

        $invoices = Auth::user()->invoices()
            ->notPrivate()
            ->withSum('invoiceItems as total_amount', 'total_price')
            ->orderBy('created_at', 'desc')
            ->paginate(30);

        $manualTopupDesc = Post::manualTopup();


        return view('member.billing.index', compact('waitingPayments', 'invoices', 'manualTopupDesc'));
    }

    public function topup(Request $request)
    {
        $genuuid = Str::uuid();
        // validasi spam depo
        $check_data = [
            'deposit_limit'  => Invoice::where([['user_id', Auth::user()->id], ['status', 'UNPAID']])->whereDate('created_at', date('Y-m-d'))->get(),
        ];

        if (count($check_data['deposit_limit']) >= 3) {
            throw ValidationException::withMessages([
                'amount' => __('you still have 3 pending balances, please pay the previous balances!!!'),
            ]);
        }
        
        if ($request->amount < 9000) {
            alert(__('Failed'), 'warning', __('Minimum topup is Rp. 10.000'));
            return redirect('dashboard');
        }
        
        $validator = validator($request->all(), [
            'amount' => 'numeric|required|min:10000|max:50000',
            'payment_code' => 'required|string',
        ], [
            'payment_code.required' => __('Select payment method first!')
        ]);

        $validator->validate();

        /** @var \App\Models\User */
        $user = $request->user();

        if (!$user->userAddress) {
            alert(__('Info'), 'info', __('Please set your billing address before topup'));
            return redirect(route('member.setting.index') . '#addressForm');
        }

        $transaction = Tripay::open()->createTransaction(
            $transactionData = new TripayTransaction(
                method: $request->payment_code,
                merchantRef: sprintf('TOP%s%s', $genuuid, time()),
                amount: $request->amount,
                customerName: $user->userAddress->name,
                customerEmail: $user->email,
                customerPhone: $user->userAddress->phone_number,
                orderItems: [
                    new TripayOrderItem(
                        name: 'Topup Balance',
                        price: $request->amount,
                        quantity: 1
                    )
                ],
                returnUrl: route('member.billing.index'),
                expiredTime: strtotime('+1 Hours'),
                callbackUrl: route('member.billing.paymentNotification')
            )
        );

        if (!$transaction) {
            alert(__('Failed'), 'warning', $message = __('Failed while checkout. Please contact the admin.'));
            info($message, ['user' => $user, 'transaction' => $transactionData]);
            return redirect()->back();
        } else {
            $invoice = $user->invoices()->create([
                'ref' => $transaction['merchant_ref'],
                'payment_ref' => $transaction['reference'],
                'payment_method' => sprintf(
                    'TRIPAY.%s.%s',
                    $transaction['payment_method'],
                    $transaction['payment_name']
                ),
                'title' => __('Topup Balance'),
                'type' => 'TOPUP',
                'status' => $transaction['status'],
                'is_private' => false,
                'checkout_url' => $transaction['checkout_url'],
                'customer_details' => [
                    'name' => $transaction['customer_name'],
                    'email' => $transaction['customer_email'],
                    'phone' => $transaction['customer_phone'],
                ],
                'expired_at' => now()->timestamp($transactionData->expiredTime),
            ]);

            $invoice->invoiceItems()->create([
                'title' => __('Topup Balance'),
                'qty' => 1,
                'price' => $transactionData->amount,
                'total_price' => $transactionData->amount,
            ]);

            if ((int) $transaction['fee_customer'] > 0) {
                $invoice->invoiceItems()->create([
                    'title' => __('Service Cost'),
                    'qty' => 1,
                    'price' => $transaction['fee_customer'],
                    'total_price' => $transaction['fee_customer'],
                ]);
            }

            return redirect()->intended($transaction['checkout_url']);
        }
    }

    public function manualTopup(Request $request)
    {
        $genuuid = Str::uuid();
        // validasi spam depo
        $check_data = [
            'deposit_limit'  => Invoice::where([['user_id', Auth::user()->id], ['status', 'UNPAID']])->whereDate('created_at', date('Y-m-d'))->get(),
        ];

        if (count($check_data['deposit_limit']) >= 3) {
            throw ValidationException::withMessages([
                'amount' => __('you still have 3 pending balances, please pay the previous balances!!!'),
            ]);
        }
           
        $request->validate([
            'amount' => 'numeric|required|min:10000|max:50000',
        ]);

        /** @var \App\Models\User */
        $user = $request->user();

        if (!$user->userAddress) {
            alert(__('Info'), 'info', __('Please set your billing address before topup'));
            return redirect(route('member.setting.index') . '#addressForm');
        }

        $invoice = $user->invoices()->create([
            'ref' => sprintf('TOPMANUAL%s%s', $genuuid, time()),
            'payment_method' => sprintf(
                'MANUAL.%s.%s',
                'MANUAL',
                'MANUAL TOPUP'
            ),
            'title' => __('Topup Balance'),
            'type' => 'TOPUP',
            'status' => PaymentStatusState::UNPAID->value,
            'is_private' => false,
            'checkout_url' => sprintf('invoice/TOPMANUAL%s%s', $genuuid, time()),
            'customer_details' => [
                'name' => $user->userAddress->name,
                'email' => $user->email,
                'phone' => $user->userAddress->phone_number,
            ],
            'expired_at' => now()->addHours(168),
        ]);

        $invoice->invoiceItems()->create([
            'title' => __('Topup Balance'),
            'qty' => 1,
            'price' => $request->amount,
            'total_price' => $request->amount,
        ]);

        alert(__('Success'), 'success', __('Topup request has been created. Please confirm after doing payment.'));

        return redirect()->route('member.invoice.show', $invoice->ref);
    }

    public function submitManualTopup(Request $request, $ref)
    {
        $request->validate([
            'payment_proof' => 'required|image|mimes:png,jpg,jpeg',
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        if (!$user->userAddress) {
            alert(__('Info'), 'info', __('Please set your billing address before topup'));
            return redirect(route('member.setting.index') . '#addressForm');
        }

        /** @var Invoice|null $invoice */
        $invoice = $user
            ->invoices()
            ->skipExpired()
            ->manualTopup()
            ->unpaid()
            ->where('ref', $ref)
            ->firstOrFail();

        $file = $request->file('payment_proof');
        $hmac = hash_hmac('sha256', $user->id . $invoice->ref, config('app.key'));
        $fileName = sprintf('%s.%s', substr($hmac, 0, 6) . "-" . $invoice->ref, $file->getClientOriginalExtension());
        $path = $file->storeAs('toppayproof', $fileName, [
            'disk' => 'public',
        ]);

        if ($path) {
            $invoice->update([
                'payment_proof' => $path,
                'status' => 'PROCESSING',
            ]);

            alert(__('Success'), 'success', __('Payment proof successfully uploaded.'));
        } else {
            alert(__('Error'), 'warning', __('Failed while uploading payment proof. Please contact the admin.'));
        }

        return redirect()->back();
    }

    public function paymentNotification(Request $request)
    {
        // Ambil callback signature
        $callbackSignature = $request->header('X-Callback-Signature');

        // Generate signature untuk dicocokkan dengan X-Callback-Signature
        $signature = Tripay::open()->generateCallbackNotificationSignature(json_encode($request->all()));

        // Validasi signature
        if ($callbackSignature !== $signature) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid signature',
            ]);
        }

        if (count($request->all()) < 1) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid data sent by payment gateway',
            ]);
        }

        // Hentikan proses jika callback event-nya bukan payent_status
        if ('payment_status' !== $request->header('X-Callback-Event')) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid callback event, no action was taken',
            ]);
        }

        $uniqueRef = $request->merchant_ref;
        $status = strtoupper((string) $request->status);
        $paidAt = $request->paid_at;

        if (1 === (int) $request->is_closed_payment) {
            $invoice = Invoice::where('ref', $uniqueRef)->first();
            $previousStatus = optional($invoice)->getRawOriginal('status');

            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice not found or alreaddy paid: ' . $uniqueRef,
                ]);
            }

            $balance = $request->total_amount - $request->fee_customer;

            $status = DB::transaction(function () use ($invoice, $status, $previousStatus, $balance, $paidAt) {
                $updateInvoice = $invoice->update([
                    'status' => $status,
                    'paid_at' => $paidAt,
                ]);

                if ($status === 'PAID' && $previousStatus != 'PAID' && $updateInvoice) {
                    $invoice->user()->increment('balance', $balance);
                    return true;
                }

                if ((in_array($status, ['REFUND', 'EXPIRED', 'FAILED'])) && $previousStatus === 'PAID' && $updateInvoice) {
                    $invoice->user()->decrement('balance', $balance);
                    return true;
                }

                return $updateInvoice;
               
            });

            if ($status) {
                return response()->json(['success' => true]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed while updating invoice status',
                
            ]);
        }
    }
}
