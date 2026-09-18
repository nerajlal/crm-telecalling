<?php

namespace App\Services;

use App\Models\Call;
use Illuminate\Support\Facades\Http;

class ExotelClient
{
    public function ready(): bool
    {
        foreach (['account_sid', 'api_key', 'api_token', 'caller_id', 'callback_base_url'] as $key) {
            if (! config('telephony.exotel.'.$key)) {
                return false;
            }
        }

        return config('telephony.live_enabled') && str_starts_with(config('telephony.exotel.callback_base_url'), 'https://') && in_array(config('telephony.exotel.host'), ['api.in.exotel.com', 'api.exotel.com']);
    }

    private function request()
    {
        return Http::withBasicAuth(config('telephony.exotel.api_key'), config('telephony.exotel.api_token'))->acceptJson()->connectTimeout(5)->timeout(20);
    }

    private function base(): string
    {
        if (! in_array(config('telephony.exotel.host'), ['api.in.exotel.com', 'api.exotel.com'])) {
            throw new \RuntimeException('Unsupported Exotel host.');
        }

        return 'https://'.config('telephony.exotel.host').'/v1/Accounts/'.rawurlencode(config('telephony.exotel.account_sid')).'/Calls';
    }

    public function initiate(Call $call): array
    {
        // Deliberately no automatic POST retry: the provider may already have dialed.
        $response = $this->request()->asForm()->post($this->base().'/connect.json', [
            'From' => $call->employee_phone, 'To' => $call->customer_phone, 'CallerId' => config('telephony.exotel.caller_id'),
            'Record' => 'false', 'TimeOut' => 30, 'TimeLimit' => 3600, 'CustomField' => $call->request_key,
            'StatusCallback' => rtrim(config('telephony.exotel.callback_base_url'), '/').'/webhooks/exotel/'.$call->id.'/'.$call->callback_token,
            'StatusCallbackEvents' => ['terminal', 'answered'], 'StatusCallbackContentType' => 'application/json',
        ]);
        if (in_array($response->status(), [400, 401, 403, 404, 422, 429])) {
            return ['rejected' => true, 'http_status' => $response->status()];
        }
        if (! $response->successful()) {
            throw new \RuntimeException('Provider response is uncertain.');
        }
        $sid = $response->json('Call.Sid');
        if (! is_string($sid) || ! preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $sid)) {
            throw new \RuntimeException('Missing provider reference.');
        }

        return ['sid' => $sid];
    }

    public function details(string $sid): array
    {
        if (! preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $sid)) {
            throw new \RuntimeException('Invalid provider reference.');
        }
        $response = $this->request()->get($this->base().'/'.rawurlencode($sid).'.json', ['details' => 'true']);
        // Do not log the HTTP exception: it can include phone numbers or provider responses.
        if (! $response->successful() || ! is_array($response->json('Call'))) {
            throw new \RuntimeException('Could not retrieve provider call details.');
        }

        return $response->json('Call');
    }
}
