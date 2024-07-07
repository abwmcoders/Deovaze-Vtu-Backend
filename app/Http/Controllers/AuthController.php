<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\RegisterUserRequest;
use App\Http\Resources\RegistrationResource;
use App\Interfaces\UserServiceInterface;
use App\Mail\OtpMail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    protected $userService;

    public function __construct(UserServiceInterface $userService)
    {
        $this->userService = $userService;
    }

    public function register(RegisterUserRequest $request)
    {
        $validatedData = $request->validated();

        $user = $this->userService->register($validatedData);

        if(!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User registration failed.',
                'data' => []
            ], 422); 
        }

        $this->sendOtp($user);

        return response()->json([
            'status' => true,
            'message' => 'User registered successfully, Please verify your email.',
            'data' => new RegistrationResource($user)
        ], 201);

    }

    public function login(LoginRequest $request)
    {
        $validatedData = $request->validated();

        try {
            $token = $this->userService->login($validatedData);
            return response()->json([
                'status' => true,
                'message' => 'Login successfully',
                'token' => $token
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'token' => ''
            ], 401);
        }
    }

    public function verifyOtp( Request $request) {
        $validatedData = $request->validate([
            'email' => 'required|string|email|max:255',
            'otp' => 'required|digits:6',
        ]);

        $user = $this->userService->verifyOtp($validatedData);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid OTP or OTP expired.'
            ], 400);
        }

        $user->email_verified_at = now();
        $user->otp = null;
        $user->otp_expires_at = null;
        $user->save();

        return response()->json([
            'status' => true,
            'message' => 'Email verified successfully.'
        ]);
    }

    public function requestPasswordReset( Request $request) {
        $validatedData = $request->validate([
            'email' => 'required|string|email|max:255',
        ]);

       $user = $this->userService->requestPasswordReset($validatedData);

        Log::info('The found user for reset: ' . $user . ': ');

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found.'
            ], 404);
        }

        $this->generateAndSendOtp($user);

        return response()->json([
            'status' => true,
            'message' => 'OTP sent for password reset.'
        ]);
    }

    public function resetPassword(Request $request) {
        $validatedData = $request->validate([
            'email' => 'required|string|email|max:255',
            'otp' => 'required|digits:6',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $this->userService->resetPassword($validatedData);

        Log::info('The found user for reset first: ' . $user . ': ');
        Log::info('request password: ' . $validatedData['password'] . ': ');

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid OTP or OTP expired.'
            ], 400);
        }

        $user->password = Hash::make($validatedData['password']);
        $user->otp = null;
        $user->otp_expires_at = null;
        $user->save();

        Log::info('The found user for reset updated: ' . $user . ': ');

        return response()->json([
            'status' => true,
            'message' => 'Password reset successfully.'
        ]);

    }

    protected function generateAndSendOtp($user)
    {
        $otp = rand(100000, 999999);
        $user->otp = $otp;
        $user->otp_expires_at = Carbon::now()->addMinutes(10);
        $user->save();

        // Send OTP email
        Mail::to($user->email)->send(new OtpMail($otp));
    }

    protected function sendOtp(User $request)
    {
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found.'
            ], 404);
        }

        $this->generateAndSendOtp($user);

        return response()->json([
            'status' => true,
            'message' => 'OTP sent successfully.'
        ]);
    }

}
