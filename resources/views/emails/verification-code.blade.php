<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Código de Verificación</title>
</head>
<body>
    <h2>Código de Verificación</h2>
    <p>Hola,</p>
    <p>Tu código de verificación es: <strong>{{ $code }}</strong></p>
    <p>Este código expirará en 15 minutos.</p>
    <p>Si no solicitaste este código, por favor ignora este email.</p>
    <br>
    <p>Saludos,<br>El equipo de {{ config('app.name') }}</p>
</body>
</html>