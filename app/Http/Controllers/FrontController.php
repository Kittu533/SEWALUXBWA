<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Http\Request;

class FrontController extends Controller
{
    public function index()
    {
        $categories = Category::all();
        $latest_product = Product::latest()->take(5)->get();
        $random_products = Product::inRandomOrder()->take(5)->get();
        return view('front.index', compact('categories', 'latest_product', 'random_products'));
    }

    public function category(Category $category)
    {
        session()->put('category_id', $category->id);
        return view('front.brands', compact('category'));
    }

    public function brand(Brand $brand)
    {
        $category_id = session()->get('category_id');

        $products = Product::where('brand_id', $brand->id)->where('category_id', $category_id)->latest()->get();

        return view('front.gadgets', compact('products', 'brand'));
    }

    public function details(Product $product)
    {
        return view('front.details', compact('product'));
    }

    public function booking(Product $product)
    {
        $stores = Store::all(); // Pastikan tidak ada kesalahan di sini
        return view('front.booking', compact('product', 'stores'));
    }


    public function booking_save(StoreBookingRequest $request, Product $product)
    {
        session()->put('product_id', $product->id);
        $bookingData = $request->only(['duration', 'started_at', 'address', 'store_id', 'delivery_type']);

        session($bookingData);

        return redirect()->route('front.checkout', $product->slug);
        // return view('front.auto_checkout', compact('product'));
    }

    public function checkout(Product $product)
    {
        $duration = session('duration');

        $insucrance = 20000;
        $ppn = 0.11;
        $price = $product->price;

        $subTotal = $price * $duration;
        $totalPpn = $subTotal * $ppn;
        $grandTotal = $subTotal + $totalPpn + $insucrance;

        return view('front.checkout', compact('product', 'duration', 'insucrance', 'subTotal', 'totalPpn', 'grandTotal'));
    }

    public function checkout_store(StorePaymentRequest $request)
    {
        $bookingData = session()->only(['duration', 'started_at', 'address', 'store_id', 'delivery_type', 'product_id']);

        $duration = (int) $bookingData['duration'];
        $startedDate = Carbon::parse($bookingData['started_at']);

        $productDetails = Product::find($bookingData['product_id']);
        if (!$productDetails) {
            return redirect()->back()->withErrors(['product_id' => 'Product not found.']);
        }
        $insucrance = 20000;
        $ppn = 0.11;
        $price = $productDetails->price;

        $subTotal = $price * $duration;
        $totalPpn = $subTotal * $ppn;
        $grandTotal = $subTotal + $totalPpn + $insucrance;

        $bookingTransactionId = null;

        DB::transaction(function () use ($request, &$bookingTransactionId, $duration, $bookingData, $grandTotal, $productDetails, $startedDate) {
            $validated = $request->validated();

            if ($request->hasFile('proof')) {
                $proofPath = $request->file('proof')->store('proofs', 'public');
                $validated['proof'] = $proofPath;
            }

            $endDate = $startedDate->copy()->addDays($duration);

            $validated['started_at'] = $startedDate;
            $validated['ended_at'] = $endDate;
            $validated['total_amount'] = $grandTotal;
            $validated['product_id'] = $productDetails->id;
            $validated['duration'] = $duration;
            $validated['store_id'] = $bookingData['store_id'];
            $validated['delivery_type'] = $bookingData['delivery_type'];
            $validated['address'] = $bookingData['address'];
            $validated['is_paid'] = false;
            $validated['trx_id'] = Transaction::generateUniqueTrxId();

            $newBooking = Transaction::create($validated);

            $bookingTransactionId = $newBooking->id;
        });
        return redirect()->route('front.success.booking', $bookingTransactionId);
    }
    public function success_booking(Transaction $transaction)
    {
        return view('front.success_booking', compact('transaction'));
    }

    public function transaction(){
        return view('front.transaction');
    }

    public function transaction_details(Request $request){
        $request->validate([
            'trx_id'=>['required','string','max:255'],
            'phone_number'=> ['required','string','max:255'],
        ]);

        $trx_id = $request->input('trx_id');
        $phone_number = $request->input('phone_number');

        $details = Transaction::with(['store','product'])->where('trx_id', $trx_id)->where('phone_number', $phone_number)->first();

        if(!$details){
            return redirect()->back()->withErrors(['error'=>'Transactions not found.']);
        }

        $ppn = 0.11;
        $insurance = 20000;
        $totalPpn = $details->total_amount * $ppn;
        $duration = $details->duration;
        $subTotal = $details->product->price * $duration;

        return view('front.transaction_details', compact('details', 'totalPpn', 'subTotal','insurance'));
    }
}
