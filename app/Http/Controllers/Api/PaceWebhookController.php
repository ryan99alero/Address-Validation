<?php

namespace App\Http\Controllers\Api;

use App\Jobs\ProcessPaceAddressCorrection;
use App\Models\IntegrationConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaceWebhookController
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $connection = IntegrationConnection::query()
            ->where('webhook_token', $token)
            ->where('is_active', true)
            ->where('driver', IntegrationConnection::DRIVER_PACE)
            ->first();

        if (! $connection) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        // Browser/health check.
        if ($request->isMethod('get')) {
            return response()->json([
                'status' => 'ready',
                'connection' => $connection->name,
                'message' => 'Pace address-correction webhook is live. POST a shipment payload here.',
            ]);
        }

        // Parse the body whether or not Pace sets Content-Type: application/json.
        $data = $request->all();
        if (empty($data)) {
            $data = json_decode($request->getContent(), true) ?: [];
        }

        $contactId = $data['contact_id'] ?? $data['contactNumber'] ?? null;
        $shipmentId = $data['shipment_id'] ?? $data['id'] ?? null;

        if (empty($shipmentId) && empty($contactId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'shipment_id (or contact_id) is required.',
            ], 422);
        }

        ProcessPaceAddressCorrection::dispatch($connection->id, $data);

        // Pace Connect only treats HTTP 200 as a successful delivery; a 202 makes it
        // retry forever (jobs pile up in Pace's outgoing queue).
        return response()->json([
            'status' => 'accepted',
            'contact_id' => $contactId,
        ], 200);
    }
}
