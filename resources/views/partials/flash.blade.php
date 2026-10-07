{{--
    Feedback contract (§18.6).

    · Transient success/warning feedback → toast (rendered by resources/js/app.js),
      because a green bar that stays on screen stops meaning anything.
    · Validation failures and authorization notices stay INLINE — they are part
      of the page state and must not disappear on a timer.
--}}
@if (session('status'))
    <span data-erp-flash="status" hidden
          data-erp-flash-title="{{ session('status') }}"
          data-erp-flash-variant="ok"></span>
@endif

@if (session('warning'))
    <span data-erp-flash="warning" hidden
          data-erp-flash-title="{{ session('warning') }}"
          data-erp-flash-variant="warn"></span>
@endif

@if (session('error'))
    <span data-erp-flash="error" hidden
          data-erp-flash-title="{{ session('error') }}"
          data-erp-flash-variant="danger"></span>
@endif

@if ($errors->any())
    <div class="alert alert-danger mb-3" role="alert">
        <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
        <div>
            <strong>{{ trans_choice('Please correct the following error|Please correct the following :count errors', $errors->count(), ['count' => $errors->count()]) }}</strong>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
