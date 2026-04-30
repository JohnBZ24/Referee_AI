# Referee AI

A sophisticated AI comparison platform that streams responses from multiple AI models simultaneously and uses a fourth AI as a referee to evaluate and select the best answer.

## Features

- **Multi-Model Streaming**: Connect to Claude, GPT-4, Gemini, and other AI models concurrently
- **Real-time Responses**: Server-Sent Events (SSE) for live streaming of AI responses
- **AI Referee**: Fourth AI model analyzes all responses and declares a winner
- **Session Management**: Create, manage, and persist conversation sessions
- **Responsive Design**: Mobile-first UI with collapsible sidebar
- **Modern Stack**: React + TypeScript frontend with Laravel backend

## Architecture

```
Frontend (React/TypeScript)          Backend (Laravel)
┌─────────────────────┐             ┌─────────────────────┐
│  User Interface     │             │  API Routes         │
│  - Model Panels     │◄───────────►│  - Sessions         │
│  - Prompt Input     │  SSE/fetch  │  - Prompts          │
│  - Referee Verdict  │             │  - Streaming        │
└─────────────────────┘             └─────────────────────┘
                                            │
                                            ▼
                                ┌─────────────────────┐
                                │  AI Service         │
                                │  - Parallel Streams │
                                │  - Multi-providers  │
                                └─────────────────────┘
                                            │
                                ┌───────────┼───────────┬───────────┐
                                ▼           ▼           ▼           ▼
                          ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐
                          │Claude   │ │GPT-4    │ │Gemini   │ │Referee  │
                          │Model 1  │ │Model 2  │ │Model 3  │ │Model    │
                          └─────────┘ └─────────┘ └─────────┘ └─────────┘
```

## Getting Started

### Prerequisites

- PHP 8.4+
- Node.js 18+
- PostgreSQL
- API keys for AI providers (OpenAI, Anthropic, Google, Moonshot)

### Backend Setup

1. Navigate to backend directory:
```bash
cd backend/backend
```

2. Install dependencies:
```bash
composer install
```

3. Configure environment:
```bash
cp .env.example .env
```

4. Update `.env` with your database and API keys:
```env
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password

ANTHROPIC_API_KEY=your_key
OPENAI_API_KEY=your_key
GOOGLE_AI_API_KEY=your_key
MOONSHOT_API_KEY=your_key
```

5. Run migrations:
```bash
php artisan migrate
```

6. Start the development server:
```bash
php artisan serve
```

The backend will run on `http://localhost:8000`

### Frontend Setup

1. Navigate to frontend directory:
```bash
cd frontend
```

2. Install dependencies:
```bash
npm install
```

3. Configure environment (if needed):
The default `.env.local` is already configured for `http://localhost:8000`

4. Start the development server:
```bash
npm run dev
```

The frontend will run on `http://localhost:5173`

## API Endpoints

### Authentication
- `POST /api/v1/auth/register` - Register new user
- `POST /api/v1/auth/login` - Login user
- `POST /api/v1/auth/logout` - Logout user
- `GET /api/v1/auth/me` - Get current user

### Models
- `GET /api/v1/models` - List available AI models

### Sessions
- `GET /api/v1/sessions` - List all sessions
- `POST /api/v1/sessions` - Create new session
- `GET /api/v1/sessions/{id}` - Get session details
- `DELETE /api/v1/sessions/{id}` - Delete session

### Prompts
- `POST /api/v1/sessions/{session}/prompt` - Submit prompt (SSE streaming)

## Streaming Response Format

The prompt submission endpoint uses Server-Sent Events (SSE) to stream responses:

```javascript
event: panelist_chunk
data: {"message_id": 1, "position": 1, "content": "Hello", "complete": false}

event: panelist_complete
data: {"message_id": 1, "position": 1, "tokens": 150}

event: referee_chunk
data: {"message_id": 4, "content": "Winner: Claude"}

event: referee_complete
data: {"message_id": 4, "winner": "Claude 3.5 Sonnet", "summary": "Analysis..."}

event: done
data: {"session_id": 123}
```

## Configuration

AI models are configured in `backend/config/ai.php`:

```php
'models' => [
    'claude-3-5-sonnet' => [
        'name' => 'Claude 3.5 Sonnet',
        'provider' => 'anthropic',
        'model_id' => 'claude-3-5-sonnet-20241022',
    ],
    // ... more models
],

'default_panelists' => ['claude-3-5-sonnet', 'gpt-4o', 'gemini-1-5-pro'],
'default_referee' => 'claude-3-5-sonnet',
```

## Testing

Run backend tests:
```bash
cd backend/backend
php artisan test
```

Run frontend tests:
```bash
cd frontend
npm run test
```

## Deployment

### Frontend Build
```bash
cd frontend
npm run build
```

### Backend Production
```bash
cd backend/backend
php artisan optimize
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## License

MIT

## Contributing

1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request