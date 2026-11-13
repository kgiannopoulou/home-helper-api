<?php

namespace App\Kitchen\Barcodes;

use RuntimeException;

/** The product service couldn't answer: try again later, or name the product by hand */
class LookupFailed extends RuntimeException {}
