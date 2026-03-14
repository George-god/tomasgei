<?php
declare(strict_types=1);

namespace Game\UI;

session_start();

$playerName = $_POST['player_name'] ?? $_SESSION['player_name'] ?? '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['player_name'])) {
    $playerName = trim($_POST['player_name']);
    
    if (empty($playerName)) {
        $error = 'Please enter your name, young cultivator.';
    } elseif (strlen($playerName) < 2) {
        $error = 'Your name must be at least 2 characters long.';
    } elseif (strlen($playerName) > 50) {
        $error = 'Your name is too long. Please keep it under 50 characters.';
    } elseif (!preg_match('/^[a-zA-Z0-9\s\-_\']+$/u', $playerName)) {
        $error = 'Your name contains invalid characters. Use only letters, numbers, spaces, hyphens, underscores, and apostrophes.';
    } else {
        $_SESSION['player_name'] = $playerName;
        header('Location: game.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cultivation Journey - Begin Your Path</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Georgia', 'Times New Roman', serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            color: #e8e8e8;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 30%, rgba(100, 200, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(255, 200, 100, 0.1) 0%, transparent 50%);
            animation: pulse 8s ease-in-out infinite;
            pointer-events: none;
        }

        @keyframes pulse {
            0%, 100% { opacity: 0.5; }
            50% { opacity: 1; }
        }

        .container {
            background: rgba(20, 25, 40, 0.9);
            border: 2px solid rgba(100, 200, 255, 0.3);
            border-radius: 20px;
            padding: 3rem 2.5rem;
            max-width: 500px;
            width: 90%;
            box-shadow: 
                0 0 30px rgba(100, 200, 255, 0.2),
                inset 0 0 30px rgba(100, 200, 255, 0.05);
            position: relative;
            z-index: 1;
            backdrop-filter: blur(10px);
        }

        .container::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(45deg, 
                rgba(100, 200, 255, 0.3),
                rgba(255, 200, 100, 0.3),
                rgba(100, 200, 255, 0.3));
            border-radius: 20px;
            z-index: -1;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .container:hover::before {
            opacity: 1;
        }

        h1 {
            text-align: center;
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, #64c8ff 0%, #ffc864 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-shadow: 0 0 20px rgba(100, 200, 255, 0.5);
        }

        .subtitle {
            text-align: center;
            font-size: 1rem;
            color: #a0a0a0;
            margin-bottom: 2rem;
            font-style: italic;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
            color: #c8c8c8;
        }

        input[type="text"] {
            width: 100%;
            padding: 0.75rem 1rem;
            font-size: 1.1rem;
            background: rgba(10, 15, 30, 0.8);
            border: 2px solid rgba(100, 200, 255, 0.3);
            border-radius: 8px;
            color: #e8e8e8;
            transition: all 0.3s;
            font-family: inherit;
        }

        input[type="text"]:focus {
            outline: none;
            border-color: #64c8ff;
            box-shadow: 0 0 15px rgba(100, 200, 255, 0.3);
            background: rgba(15, 20, 35, 0.9);
        }

        input[type="text"]::placeholder {
            color: #666;
        }

        .error {
            color: #ff6b6b;
            font-size: 0.9rem;
            margin-top: 0.5rem;
            padding: 0.5rem;
            background: rgba(255, 107, 107, 0.1);
            border-left: 3px solid #ff6b6b;
            border-radius: 4px;
        }

        button {
            width: 100%;
            padding: 1rem;
            font-size: 1.2rem;
            font-weight: bold;
            background: linear-gradient(135deg, #64c8ff 0%, #4a9eff 100%);
            border: none;
            border-radius: 8px;
            color: #1a1a2e;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: 0 4px 15px rgba(100, 200, 255, 0.3);
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(100, 200, 255, 0.5);
            background: linear-gradient(135deg, #7dd3ff 0%, #5aafff 100%);
        }

        button:active {
            transform: translateY(0);
        }

        .hint {
            text-align: center;
            margin-top: 1rem;
            font-size: 0.85rem;
            color: #888;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🌌 Cultivation Journey</h1>
        <p class="subtitle">Begin your path to immortality</p>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="player_name">What is your name, young cultivator?</label>
                <input 
                    type="text" 
                    id="player_name" 
                    name="player_name" 
                    placeholder="Enter your name..."
                    value="<?php echo htmlspecialchars($playerName, ENT_QUOTES, 'UTF-8'); ?>"
                    required
                    autofocus
                    autocomplete="name"
                >
                <?php if ($error): ?>
                    <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            </div>
            
            <button type="submit">Begin Cultivation</button>
        </form>
        
        <p class="hint">Your name will be remembered throughout your journey</p>
    </div>
</body>
</html>




