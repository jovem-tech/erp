@php
    $apiErrorDetails = session('api_error_details', []);
@endphp

@if ($errors->any() || !empty($apiErrorDetails))
    <div class="alert-shell alert-shell-danger mb-4">
        <div class="d-flex align-items-start gap-3">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div>
                <strong>Existem pendências que precisam de atenção.</strong>
                <ul class="mb-0 mt-2 ps-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach

                    @foreach ($apiErrorDetails as $field => $messages)
                        @foreach ((array) $messages as $message)
                            <li>{{ is_string($message) ? $message : $field }}</li>
                        @endforeach
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
@endif
