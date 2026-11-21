<?php

use App\Mcp\Servers\PayPalServer;
use Laravel\Mcp\Facades\Mcp;

// Local only: a stdio server started with `paypalmcp mcp:start paypal`. There is no Mcp::web()
// here and there must never be one - nothing in this tool listens on a port.
Mcp::local('paypal', PayPalServer::class);
