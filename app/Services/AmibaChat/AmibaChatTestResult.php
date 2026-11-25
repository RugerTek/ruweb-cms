<?php

namespace App\Services\AmibaChat;

/** What Amiba answered to the "Test" button (AmibaChatOperations::test()), ready for the panel. */
final class AmibaChatTestResult
{
    /** The widget exists and this site is in its allowed origins. */
    public const OK = 'ok';

    /** The widget exists and its allowed origins are empty: Amiba lets any site show it. */
    public const OK_ANY_SITE = 'ok_any_site';

    /** Amiba does not know the handle, or its Web Chat is off. */
    public const NOT_FOUND = 'not_found';

    /** The widget exists but this site's origin is not in its allowed origins. */
    public const ORIGIN_NOT_ALLOWED = 'origin_not_allowed';

    /** Amiba did not answer in time or could not be reached. */
    public const UNREACHABLE = 'unreachable';

    /** Any other answer (rate limit, server error). */
    public const UNEXPECTED = 'unexpected';

    /**
     * @param  string  $origin  this site's origin, as it must be written in Amiba
     * @param  list<string>  $allowedOrigins  the widget's allowed origins, as Amiba returned them
     */
    public function __construct(
        public readonly string $outcome,
        public readonly string $origin,
        public readonly string $title = '',
        public readonly array $allowedOrigins = [],
        public readonly ?int $status = null,
    ) {}

    public function ok(): bool
    {
        return in_array($this->outcome, [self::OK, self::OK_ANY_SITE], true);
    }

    /** The panel message (lang/amiba_chat.php `test_{outcome}`). */
    public function message(): string
    {
        return __('amiba_chat.test_'.$this->outcome, [
            'origin' => $this->origin,
            'title' => $this->title !== '' ? $this->title : __('amiba_chat.test_untitled'),
            'status' => (string) $this->status,
        ]);
    }
}
