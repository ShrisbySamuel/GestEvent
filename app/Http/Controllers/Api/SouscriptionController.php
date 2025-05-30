<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrganisateurProfile;
use App\Models\PlansSouscription;
use App\Models\Souscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Utilisateur;
use FedaPay\FedaPay;
use FedaPay\Transaction;
use FedaPay\Webhook;
use Illuminate\Support\Facades\Log;

class SouscriptionController extends Controller
{

    
    public function paiementsouscrire(Request $request)
{
    $request->validate([
        'plans_souscription_id' => 'required|exists:plans_souscriptions,id'
    ]);

    $plan = PlansSouscription::findOrFail($request->plans_souscription_id);
    $utilisateur = $request->user();

    try {
        FedaPay::setApiKey(env('FEDAPAY_SECRET_KEY'));
        FedaPay::setEnvironment(env('FEDAPAY_ENV', 'sandbox'));

        $reference = uniqid(); // pour suivre la transaction plus facilement

        $transaction = Transaction::create([
            // dd([

          
            "description" => "Souscription organisateur - {$utilisateur->nom} - {$plan->nom}",
            'amount' => (int) $plan->prix,
            // "amount" => 5000,
            "currency" => ["iso" => "XOF"],
            "callback_url" => env('FEDAPAY_CALLBACK_URL') . '?reference=' . $reference,
            "customer" => [
                "firstname" => $request->prenom ?: 'Inconnu',
                "lastname" => $request->nom ?: $utilisateur->nom,
                "email" => $utilisateur->email,
                "type" => "Souscription",
                "user_id" => $utilisateur->id,
                "plan_id" => $plan->id,
                "phone" => [
                    "number" => $request->telephone ?: 64000001,
                    "country" => 'BJ'
                ]
            ],
            "custom_metadata" => [
                "type" => "Souscription",
                "user_id" => $utilisateur->id,
                "plan_id" => $plan->id,
                "reference" => $reference
            ]
            // ])
        ]);

        return response()->json([
            'url' => $transaction->generateToken()->url,
            'reference' => $reference
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Erreur lors de la création de la transaction',
            'error' => $e->getMessage()
        ], 500);
    }
}



    public function souscriptionWebhook(Request $request)
{
    $endpoint_secret = 'wh_sandbox_momFrGT1lLp2F-OkMCzR_kPp';
    $payload = @file_get_contents('php://input');
    $sig_header = $_SERVER['HTTP_X_FEDAPAY_SIGNATURE'];
    $event = null;

try {
    $event = Webhook::constructEvent(
        $payload, $sig_header, $endpoint_secret
    );
} catch(\UnexpectedValueException $e) {
    // Invalid payload

    http_response_code(400);
    exit();
} catch(\FedaPay\Error\SignatureVerification $e) {
    // Invalid signature

    http_response_code(400);
    exit();
}
    // $payload = $request->all();
    
    // Vérification de l'événement

    if ($event->name === 'transaction.approved' ) {

        $transaction = $event->entity;

    
    $metadata = $transaction->custom_metadata;

    // Vérification des métadonnées
    if (!isset($metadata->user_id, $metadata->plan_id, $metadata->reference)) {
        // Log::error("Métadonnées manquantes dans le webhook : " . json_encode($metadata));
        return response()->json(['message' => 'Métadonnées manquantes'], 400);
    }

    $user = Utilisateur::find($metadata->user_id);
    $plan = PlansSouscription::find($metadata->plan_id);
    $reference = $metadata->reference;

    if (!$user || !$plan) {
        // Log::error("Utilisateur ou plan introuvable : User ID = {$metadata['user_id']}, Plan ID = {$metadata['plan_id']}");
        return response()->json(['message' => 'Utilisateur ou plan introuvable'], 404);
    }

    // Donner le rôle d'organisateur si ce n'est pas déjà fait
    if ($user->role !== 'organisateur') {
        $user->role = 'organisateur';
        $user->save();
    }

    // Créer l’organisateur si nécessaire
    $organisateur = $user->organisateurProfile;
    if (!$organisateur) {
        $organisateur = OrganisateurProfile::create([
            'utilisateur_id' => $user->id,
        ]);
    }

    // Vérification pour éviter de dupliquer l'enregistrement
    $existingSouscription = Souscription::where('reference', $reference)->first();
    if (!$existingSouscription) {
        // Enregistrer la souscription
        $souscription = Souscription::create([
            'organisateur_id' => $organisateur->id,
            'utilisateur_id' => $user->id,
            'plans_souscription_id' => $plan->id,
            'date_debut' => now(),
            'date_fin' => now()->addDays($plan->duree_jours),
            'methode' => 'mobile_money',
            'statut' => 'actif',
            'statut_paiement' => 'success',
            'montant' => $plan->prix,
            'reference' => $reference,
        ]);

        // Log::info("Souscription enregistrée avec succès : " . json_encode($souscription));

        return response()->json([
            'message' => 'Souscription enregistrée',
            'souscription' => $souscription
        ], 200);
    } else {
        // Log::warning("Souscription déjà existante pour la référence : " . $reference);
        return response()->json([
            'message' => 'Souscription déjà enregistrée',
            'souscription' => $existingSouscription
        ], 200);


    }
    }
        return response()->json(['message' => 'Événement non géré'], 400);

    
    
}
    /**
     * Summary of souscrire
     * @param \Illuminate\Http\Request $request
     * @return mixed|\Illuminate\Http\JsonResponse
     */
    // public function souscrire(Request $request){
    //     $request->validate([
    //        'plans_souscription_id' => 'required|exists:plans_souscriptions,id',
    //     ]);

    //     $plan = PlansSouscription::find($request->plans_souscription_id);
    //     $utilisateur = $request->user();
         
    //     //expirer l'ancienne souscription active si elle existe
    //     $ancienne = $utilisateur->souscriptionActive();
    //     if($ancienne) {
    //         $ancienne->statut = 'expiré';
    //         $ancienne->save();
    //     }

    //     // creer une nouvelle ligne
    //     $souscription = new Souscription([
    //         'plans_souscription_id' => $plan->id,
    //         'date_debut' => now(),
    //         'date_fin' => now()->addDays($plan->duree_jours),
    //         'statut' => 'actif',
    //     ]);
    //     $utilisateur->souscription()->save($souscription);

    //     // vérification du role 'organisateur'
    //     if($utilisateur->role !== 'organisateur'){
    //         $utilisateur->role = 'organisateur';
    //         $utilisateur->save();
    //     }

    //     $user = Auth::user(); 
    //     $orga = OrganisateurProfile::where('utilisateur_id', $user->id)->first();

        
    //     if(!$orga){
    //       OrganisateurProfile::create([
    //         'utilisateur_id' => $user->id
    //       ]) ;
    //     }

    //     return response()->json([
    //         'message' => 'Souscription éffectué.',
    //         'souscription' => $souscription
    //     ]);
    // }

    // Voir les plans disponibles
    /**
     * Summary of plans
     * @return mixed|\Illuminate\Http\JsonResponse
     */
    public function plans()
    {
        $plans = PlansSouscription::all();
        return response()->json($plans);
    }


    public function monAbonnement(Request $request){
        $active = $request->user()->souscriptionActive();

        if (!$active){
            return response()->json([
                'message' => 'Aucune souscription active.'
            ], 404);

        }
        $plan = $active->plan;
        return response()->json([
            'plan' => $plan,
            'souscription' => $active
        ]);

    }

     // Vérifier si la souscription est valide
     /**
      * Summary of statut
      * @param \Illuminate\Http\Request $request
      * @return mixed|\Illuminate\Http\JsonResponse
      */
     public function statut(Request $request)
     {
         $user = Auth::user();
 
         $active = $user->souscription()->where('statut', 'actif')->where('date_fin', '>', now())->first();
 
         return response()->json([
             'active' => !!$active,
             'souscription' => $active
         ]);
     }
 
     // Historique
     /**
      * Summary of historique
      * @return mixed|\Illuminate\Http\JsonResponse
      */
     public function historique()
     {
        // $hist = $request->user()->souscriptionActive()->with('plan')->latest()->get();
        // return response()->json($hist);

         return response()->json(auth()->user()->souscription()->with('plan')->latest()->get());
        }
 

}
