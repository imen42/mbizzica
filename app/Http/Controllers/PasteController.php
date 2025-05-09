<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Paste;
use Illuminate\Support\Facades\Storage; 
use Illuminate\Support\Facades\Hash;


class PasteController extends Controller
{
    public function create()
    {
        return view('pastes.create'); 
    }

    public function store(Request $request)
    {
        $request->validate([
            'content' => 'required|string',
            'title' => 'nullable|string|max:255',
            'tags' => 'nullable|string|max:255',
            'visibility' => 'required|in:public,private,unlisted',
            'password' => 'nullable|string|min:6', 
            'expires_at' => 'nullable|date|after:now', 
            'attachment' => 'nullable|file', 
        ]);
    
        $filePath = null;
        if ($request->hasFile('attachment')) {
            $filePath = $request->file('attachment')->store('attachments');
        }
    
        $paste = Paste::create([
            'user_id' => auth()->id(), 
            'content' => $request->content,
            'title' => $request->title,
            'tags' => $request->tags,
            'visibility' => $request->visibility,
            'password' => bcrypt($request->password), 
            'expires_at' => $request->expires_at,
            'attachment_path' => $filePath,
        ]);
    
        return redirect()->route('pastes.index')->with('success', 'Paste created successfully!');
    }

    public function index(Request $request)
{
    
    $query = Paste::query();

    if (auth()->check()) {
        // Logged-in users should see their own pastes (both private and public)
        $query->where(function ($q) {
            $q->where('user_id', auth()->id())  // Show the logged-in user's pastes
              ->orWhere('visibility', 'public') // Show public pastes for all users
              ->orWhere('visibility', 'unlisted'); // Show unlisted pastes to logged-in users
        });
    } else {
        // Guests should only see public pastes
        $query->where('visibility', 'public');
    }

    // Only show non-expired or non-expiring pastes
    $query->where(function ($q) {
        $q->whereNull('expires_at')
          ->orWhere('expires_at', '>', now());
    });

        $query->where(function ($q) use ($request) {
            if ($request->filled('search')) {
                $search = $request->input('search');
                $q->orWhere('title', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%")
                  ->orWhere('tags', 'like', "%{$search}%");
            }
        
            if ($request->filled('created_at')) {
                $q->orWhereDate('created_at', $request->input('created_at'));
            }
        
            if ($request->filled('expires_at')) {
                $q->orWhereDate('expires_at', $request->input('expires_at'));
            }
        });
        

    $pastes = $query->latest()->paginate(10);

    return view('pastes.index', compact('pastes'));
}
    
public function show(Paste $paste)
{
    $user = auth()->user();

    if ($paste->visibility === 'private' && (!$user || $user->id !== $paste->user_id)) {
        abort(403, 'Unauthorized access to private paste.');
    }

    if ($paste->visibility === 'unlisted' && !$user) {
        abort(403, 'Unlisted pastes are not listed.');
    }


    return view('pastes.show', compact('paste'));
}


public function verifyPassword(Request $request, Paste $paste)
{
    if (!Hash::check($request->password, $paste->password)) {
        return back()->withErrors(['password' => 'Incorrect password.']);
    }

    session([
        "paste_access_{$paste->id}" => true,
        "paste_access_time_{$paste->id}" => now()
    ]);

    return redirect()->route('pastes.show', $paste);
}
public function showBySlug($slug)
{
    $paste = Paste::where('slug', $slug)->firstOrFail();

    if ($paste->visibility === 'private' && (!auth()->check() || auth()->id() !== $paste->user_id)) {
        abort(403, 'Unauthorized access to private paste.');
    }

    if ($paste->password && !session("paste_access_{$paste->id}")) {
        return view('pastes.password', compact('paste'));
    }

    return view('pastes.show', compact('paste'));
}
public function storeComment(Request $request, Paste $paste)
{
    $request->validate([
        'content' => 'required|string|max:1000',
    ]);

    $paste->comments()->create([
        'user_id' => auth()->id(),
        'content' => $request->content,
    ]);

    return back()->with('success', 'Comment added!');
}

public function vote(Request $request, Paste $paste)
{
    $request->validate([
        'vote' => 'required|in:up,down', 
    ]);

    $existingVote = $paste->votes()->where('user_id', auth()->id())->first();

    if ($existingVote) {
        $existingVote->update([
            'vote' => $request->vote,
        ]);
    } else {
        $paste->votes()->create([
            'user_id' => auth()->id(),
            'vote' => $request->vote,
        ]);
    }

    return back()->with('success', 'Vote registered!');
}


}
