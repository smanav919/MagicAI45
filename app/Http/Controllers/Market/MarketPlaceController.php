<?php

namespace App\Http\Controllers\Market;

use App\Http\Controllers\Controller;
use App\Models\Extension;
use App\Models\Setting;
use Illuminate\Support\Facades\Auth;

use GuzzleHttp\Client;

class MarketPlaceController extends Controller
{

    public function updateDatabase()
    {
        $client = new Client();
        $settings = Setting::first();

        $response = $client->request('POST', env('API_SERVER_URL') . '/api/extensions/all', []);

        $responseData = json_decode($response->getBody(), true);

        error_log($response->getBody());

        foreach ($responseData['extensions'] as $extensionData) {

            $extension = Extension::where('slug', $extensionData['slug'])->first();
            if ($extension == null) {
                $extension = new Extension();
            }

            $extension->slug = $extensionData['slug'];
            $extension->name = $extensionData['name'];
            $extension->review = $extensionData['review'];
            $extension->description = $extensionData['description'];
            $extension->category = $extensionData['category'];
            $extension->badge = $extensionData['badge'];
            $extension->zip_url = $extensionData['zip_url'];
            $extension->image_url = $extensionData['image_url'];
            $extension->detail = $extensionData['detail'];
            $extension->price_id = $extensionData['price_id'];
            $extension->price = $extensionData['price'];
            // $extension->version = $extensionData['version'];
           
                $extension->licensed = true;
            

            $extension->save();
        }

        $settings = Setting::first();

        $response = $client->request('POST', env('API_SERVER_URL') . '/api/extensions/licensed', [
            'json' => [
                'licenseKey' => $settings->liquid_license_domain_key
            ]
        ]);

        $responseData = json_decode($response->getBody(), true);

        // Loop through the extensions and log the extensionSlug field
        foreach ($responseData['extensions'] as $extensionData) {
            $extensionSlug = $extensionData['extensionSlug'];
            $extension = Extension::where('slug', $extensionSlug)->first();
            error_log($extensionSlug);
            $extension->licensed = true;
            $extension->save();
        }
    }

    public function index()
    {
        // $jsonFile = base_path('addons.json');
        // $addonsData = File::get($jsonFile);
        // $addons = json_decode($addonsData);

        $this->updateDatabase();

        $extensions = Extension::all();
        return view('panel.admin.market.index', compact('extensions'));
    }

    public function extension($slug)
    {
        $extension = Extension::where('slug', $slug)->first();

        $client = new Client();
        $response = $client->request('GET', env('API_SERVER_URL') . "/api/extensions/qa?slug=$extension->slug");

        $responseData = json_decode($response->getBody(), true);
        $extensionQAs = $responseData['extensionQAs'];

        return view('panel.admin.market.extension', compact('extension', 'extensionQAs'));
    }

    public function licensedExtension()
    {
        $settings = Setting::first();
        
            $extensions = Extension::all();
        
        return view('panel.admin.market.liextension', compact('extensions'));
    }

    public function buyExtension($slug)
    {
        $extension = Extension::where('slug', $slug)->first();

        return view('panel.admin.market.buyextension', compact('extension'));
    }

    public function buy($slug)
    {

        $stripe = new \Stripe\StripeClient(env('EXTENSION_STRIPE_PRIVATE_KEY'));

        $client = new Client();
        $settings = Setting::first();

       
            $response = $client->request('POST', env('API_SERVER_URL') . "/api/license/$settings -> liquid_license_domain_key");

            $email = json_decode($response->getBody(), true)['owner']['email'];
       

        $extension = Extension::where('slug', $slug)->first();

        $session = $stripe->checkout->sessions->create([
            'customer_email' => $email,
            'payment_method_types' => ['card'],
            'line_items' => [
                [
                    'price' => $extension->price_id,
                    'quantity' => 1,
                ],
            ],
            'mode' => 'payment',
            'allow_promotion_codes' => true,
            'success_url' => route('dashboard.admin.marketplace.index'),
            'cancel_url' => route('dashboard.admin.marketplace.index'),
            'metadata' => [
                'licenseKey' => $settings->liquid_license_domain_key,
                'slug' => $slug,
                'email' => $email
            ]
        ]);

        return $session;
    }
}
