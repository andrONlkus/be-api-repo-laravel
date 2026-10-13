<?php

namespace App\Traits;

/**
 * Format respons standar (sama dengan be-new-lms): {status: S|E, message, data}.
 * Error validasi/404/403 dibentuk di bootstrap/app.php (withExceptions).
 */
trait HttpResponses
{
    protected function success($data, $message = null, $code = 200)
    {
        return response()->json([
            'status' => 'S',
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    protected function error($data, $message = null, $code = 400)
    {
        return response()->json([
            'status' => 'E',
            'message' => $message,
            'data' => $data,
        ], $code);
    }
}
