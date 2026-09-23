<?php

declare(strict_types=1);

/*
 * auto_prepend_file of the test application with QM_AUTO_PREPEND=1 (see AppRunner), YQM-28. The CLI lists
 * web/index.php before this file in get_included_files(), other SAPIs may reverse it; CallerFrames skips
 * this file by its path, whatever the order.
 */
