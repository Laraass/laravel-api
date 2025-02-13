<?php

namespace App\Http\Controllers;

use App\Models\ChatHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;

class ChatbotController extends Controller
{
    public function chat(Request $request)
    {
        $request->validate([
            "message" => "required|string",
            "session_id" => "nullable|string",
        ]);

        $user = Auth::user();
        
        // If session_id exists, load previous messages
        if ($user && $request->session_id) {
            $previousMessages = ChatHistory::where('user_id', $user->id)
                ->where('session_id', $request->session_id)
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(fn($chat) => [
                    ['role' => 'user', 'content' => $chat->user_message],
                    ['role' => 'assistant', 'content' => $chat->bot_response],
                ])
                ->flatten(1)
                ->toArray();

            $session_id = $request->session_id;
        } else {
            // If no session_id exists, create new session
            $session_id = (string) Uuid::uuid4();
            $previousMessages = [];
        }

        // Formats the data
        $messages = array_merge($previousMessages, [
            ['role' => 'user', 'content' => $request->message]
        ]);

        // Send the messages to the LLM
        $response = Http::post('http://localhost:11434/api/chat', [
            'model' => 'mistral',
            'messages' => $messages,
            'stream' => false,
        ]);

        $bot_response = data_get($response->json(), "message.content", "No response");

        // Save conversation if user is logged in
        if ($user) {
            ChatHistory::create([
                'user_id' => $user->id,
                'session_id' => $session_id,
                'user_message' => $request->message,
                'bot_response' => $bot_response,
            ]);
        }

        return response()->json([
            "session_id" => $session_id,
            "message" => $bot_response
        ]);
    }
}