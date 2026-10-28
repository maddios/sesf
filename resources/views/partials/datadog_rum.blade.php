{{-- Datadog RUM browser SDK (legacy views/partials/datadog_rum.blade.php). Rendered only when RUM is enabled and both
     the application id and the public client token are set. Only public RUM values reach the page. --}}
@php
    $rum = config('sesf.datadog.rum');
    $rumConfig = ($rum['enabled'] ?? false) && ($rum['application_id'] ?? '') !== '' && ($rum['client_token'] ?? '') !== ''
        ? [
            'applicationId' => (string) $rum['application_id'],
            'clientToken' => (string) $rum['client_token'],
            'site' => (string) $rum['site'],
            'service' => (string) $rum['service'],
            'env' => (string) app()->environment(),
            'version' => (string) $rum['version'],
            'sessionSampleRate' => (int) $rum['session_sample_rate'],
            'sessionReplaySampleRate' => (int) $rum['session_replay_sample_rate'],
            'trackResources' => (bool) $rum['track_resources'],
            'trackUserInteractions' => (bool) $rum['track_user_interactions'],
            'trackLongTasks' => (bool) $rum['track_long_tasks'],
        ]
        : null;
@endphp
@if ($rumConfig !== null)
<script src="https://www.datadoghq-browser-agent.com/us5/v6/datadog-rum.js" crossorigin="anonymous"></script>
<script>
    if (window.DD_RUM) {
        window.DD_RUM.init(@json($rumConfig));
    }
</script>
@endif
