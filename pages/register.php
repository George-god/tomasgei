<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/services/AuthService.php';

use Game\Config\Database;
use Game\Service\AuthService;


session_start();

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: game.php');
    exit;
}

$errors = [];
$formData = [
    'username' => '',
    'email' => ''
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $formData['username'] = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $formData['email'] = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

    $authService = new AuthService();
    $result = $authService->register($username, $email, $password, $confirmPassword);

    if ($result['success']) {
        // Registration successful - set session and redirect
        $_SESSION['user_id'] = $result['user']->getId();
        $_SESSION['username'] = $result['user']->getUsername();
        $_SESSION['is_admin'] = $result['user']->isAdmin();
        $_SESSION['realm_id'] = $result['user']->getRealmId();
        $_SESSION['level'] = $result['user']->getLevel();
        
        // Regenerate session ID for security
        session_regenerate_id(true);
        
        header('Location: game.php');
        exit;
    } else {
        $errors = $result['errors'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen flex items-center justify-center p-4">
    <!-- Background glow effects -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-cyan-500/10 rounded-full blur-3xl animate-pulse"></div>
        <div class="absolute bottom-1/4 right-1/4 w-96 h-96 bg-blue-500/10 rounded-full blur-3xl animate-pulse delay-1000"></div>
    </div>

    <div class="relative z-10 w-full max-w-md">
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-cyan-400 to-blue-400 bg-clip-text text-transparent mb-2">
                🌌 Cultivation Journey
            </h1>
            <p class="text-gray-400 text-sm">Begin your path to immortality</p>
        </div>

        <!-- Registration Card -->
        <div class="bg-gray-800/90 backdrop-blur-lg border border-cyan-500/30 rounded-xl shadow-2xl shadow-cyan-500/10 p-8">
            <h2 class="text-2xl font-semibold text-white mb-6 text-center">Create Your Account</h2>

            <!-- General Error -->
            <?php if (isset($errors['general'])): ?>
                <div class="mb-4 p-3 bg-red-500/20 border border-red-500/50 rounded-lg text-red-300 text-sm">
                    <?php echo htmlspecialchars($errors['general'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-5">
                <!-- Username Field -->
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-300 mb-2">
                        Username
                    </label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        value="<?php echo $formData['username']; ?>"
                        required
                        autofocus
                        autocomplete="username"
                        class="w-full px-4 py-3 bg-gray-900/50 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 transition-all"
                        placeholder="Enter your username"
                    >
                    <?php if (isset($errors['username'])): ?>
                        <p class="mt-1 text-sm text-red-400"><?php echo htmlspecialchars($errors['username'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Email Field -->
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-300 mb-2">
                        Email
                    </label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?php echo $formData['email']; ?>"
                        required
                        autocomplete="email"
                        class="w-full px-4 py-3 bg-gray-900/50 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 transition-all"
                        placeholder="Enter your email"
                    >
                    <?php if (isset($errors['email'])): ?>
                        <p class="mt-1 text-sm text-red-400"><?php echo htmlspecialchars($errors['email'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Password Field -->
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-300 mb-2">
                        Password
                    </label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        required
                        autocomplete="new-password"
                        class="w-full px-4 py-3 bg-gray-900/50 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 transition-all"
                        placeholder="Enter your password (min. 8 characters)"
                    >
                    <?php if (isset($errors['password'])): ?>
                        <p class="mt-1 text-sm text-red-400"><?php echo htmlspecialchars($errors['password'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Confirm Password Field -->
                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-gray-300 mb-2">
                        Confirm Password
                    </label>
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        required
                        autocomplete="new-password"
                        class="w-full px-4 py-3 bg-gray-900/50 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 transition-all"
                        placeholder="Confirm your password"
                    >
                </div>

                <!-- Submit Button -->
                <button
                    type="submit"
                    class="w-full py-3 bg-gradient-to-r from-cyan-500 to-blue-500 hover:from-cyan-600 hover:to-blue-600 text-white font-semibold rounded-lg shadow-lg shadow-cyan-500/30 hover:shadow-cyan-500/50 transition-all transform hover:-translate-y-0.5"
                >
                    Begin Cultivation
                </button>
            </form>

            <!-- Login Link -->
            <div class="mt-6 text-center">
                <p class="text-gray-400 text-sm">
                    Already have an account?
                    <a href="login.php" class="text-cyan-400 hover:text-cyan-300 font-medium transition-colors">
                        Log in
                    </a>
                </p>
            </div>
        </div>

        <!-- Footer -->
        <p class="text-center text-gray-500 text-xs mt-6">
            Your cultivation journey awaits...
        </p>
    </div>
</body>
</html>




