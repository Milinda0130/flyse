<?php

/*
| Ticket email settings. Mail transport (SMTP host, credentials, sender address) comes from
| config/mail.php and the MAIL_* environment variables; nothing here is a secret.
*/
return [
    // Largest total attachment size per email, in MB. Most providers reject messages over
    // 20-25 MB (Gmail: 25 MB including encoding overhead), so the default stays below that.
    'max_total_mb' => (int) env('TICKET_EMAIL_MAX_MB', 18),

    // Largest single PDF, in MB (uploads are already limited to 10 MB).
    'max_file_mb' => (int) env('TICKET_EMAIL_MAX_FILE_MB', 10),

    // Send through the queue (recommended: run `php artisan queue:work`, which `composer dev` does).
    'queue' => (bool) env('TICKET_EMAIL_QUEUE', true),
];
