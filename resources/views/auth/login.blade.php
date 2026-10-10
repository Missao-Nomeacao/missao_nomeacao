<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Login - Missão Nomeação</title>
    @include('components.analytics')
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#BF8F00',
                        'primary-light': '#D4A574',
                    }
                }
            }
        }
    </script>
</head>

<body class="bg-site text-white">
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8">
            <div>
                <h2 class="mt-6 text-center text-3xl font-extrabold text-[#F5F7FA]">
                    Missão Nomeação
                </h2>
                <p class="mt-2 text-center text-sm text-[#D8E6EE]">
                    Área Administrativa
                </p>
            </div>

            <form class="mt-8 space-y-6 bg-gray-900 p-8 rounded-lg shadow-md border border-red-600" action="{{ route('login') }}" method="POST">
                @csrf

                @if ($errors->any())
                <div class="bg-red-900 border border-red-600 text-red-300 px-4 py-3 rounded">
                    @foreach ($errors->all() as $error)
                    <p class="text-sm">{{ $error }}</p>
                    @endforeach
                </div>
                @endif

                <div class="rounded-md shadow-sm -space-y-px">
                    <div>
                        <label for="email" class="sr-only">Email</label>
                        <input id="email" name="email" type="email" required
                            class="appearance-none rounded-t-md relative block w-full px-3 py-2 border border-red-600 placeholder-gray-400 text-white bg-gray-800 focus:outline-none focus:ring-red-500 focus:border-red-500 focus:z-10 sm:text-sm"
                            placeholder="Email" value="{{ old('email') }}">
                    </div>
                    <div>
                        <label for="password" class="sr-only">Senha</label>
                        <input id="password" name="password" type="password" required
                            class="appearance-none rounded-b-md relative block w-full px-3 py-2 border border-red-600 placeholder-gray-400 text-white bg-gray-800 focus:outline-none focus:ring-red-500 focus:border-red-500 focus:z-10 sm:text-sm"
                            placeholder="Senha">
                    </div>
                </div>

                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <input id="remember" name="remember" type="checkbox"
                            class="h-4 w-4 text-red-600 focus:ring-red-500 border-red-600 rounded bg-gray-800">
                        <label for="remember" class="ml-2 block text-sm text-white">
                            Lembrar-me
                        </label>
                    </div>
                </div>

                <div>
                    <button type="submit"
                        class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-yellow-600 hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500">
                        Entrar
                    </button>
                </div>
            </form>

            <div class="text-center">
                <a href="/" class="text-sm text-red-600 hover:text-red-400">
                    ← Voltar para o site
                </a>
            </div>
        </div>
    </div>
</body>

</html>