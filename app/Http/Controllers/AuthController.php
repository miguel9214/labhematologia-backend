<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login: valida email/password y devuelve un token Sanctum.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $user = Auth::user();
        $user->tokens()->where('name', 'auth')->delete();
        $token = $user->createToken('auth')->plainTextToken;

        return response()->json([
            'ok'     => true,
            'message' => 'Sesión iniciada.',
            'token'  => $token,
            'user'   => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Cerrar sesión (invalidar token actual). Requiere auth:sanctum.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'ok'      => true,
            'message' => 'Sesión cerrada.',
        ]);
    }
}
