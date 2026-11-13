<?php

namespace App\Kitchen\Receipts;

use RuntimeException;

/** The photo couldn't be read: no OCR here, a broken image, or it took too long */
class OcrFailed extends RuntimeException {}
