<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'user' => $request->user()->only([
                'name', 
                'email', 
                'phoneNumber', 
                'gender', 
                'img',
                'email_verified_at'
            ])
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        try {
            $user = $request->user();
            $userData = $request->validated();
            
            if ($request->hasFile('img')) {
                $this->handleProfileImageUpload($request, $user);
            }

            $this->updateUserProfile($user, $userData);

            return Redirect::route('profile.edit')->with('status', 'Profile updated successfully');
            
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Profile update error: ' . $e->getMessage());
            return Redirect::back()->with('error', 'Profile update failed');
        }
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        try {
            $request->validate([
                'password' => ['required', 'current-password'],
            ]);

            $user = $request->user();

            Auth::logout();
            $user->delete();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return Redirect::to('/')->with('status', 'Account deleted successfully');
            
        } catch (\Exception $e) {
            Log::error('Account deletion error: ' . $e->getMessage());
            return Redirect::back()->with('error', 'Account deletion failed');
        }
    }

    /**
     * Handle profile image upload
     */
    protected function handleProfileImageUpload(Request $request, $user): void
    {
        $request->validate([
            'img' => 'required|image|max:2048',
        ]);

        $image = $request->file('img');
        $path = Storage::putFile('avatar', $image, 'public');
        
        // Delete old image if exists
        if ($user->img) {
            Storage::delete($user->img);
        }

        $user->img = $path;
    }

    /**
     * Update user profile data
     */
    protected function updateUserProfile($user, array $data): void
    {
        $user->fill($data);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->phoneNumber = $data['phoneNumber'] ?? $user->phoneNumber;
        $user->gender = $data['gender'] ?? $user->gender;
        
        $user->save();
    }

    /**
     * Handle file upload (for testing purposes)
     */
    public function upload(Request $request): void
    {
        dd($request->file());
    }
}
