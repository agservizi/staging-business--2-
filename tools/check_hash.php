<?php
$hash = '$2y$12$2xHnRJMh1zsmC1WmvMRGcuE9zraFMvx6bMpiKFFitvolG/GpNZgb2';
$passwords = [
    'admin',
    'admin123',
    'admin1234',
    'admin12345',
    'Admin123',
    'Admin@123',
    'Admin@2024',
    'Coresuite2024',
    'Coresuite!23',
    'password',
    'changeme',
    'P@ssw0rd',
    'Coresuite#2024',
    'Coresuite#2023',
    'Coresuite!2024',
    'Admin!2024',
    'Admin2024!',
    'Admin2023!',
    'Admin#2024',
    'Welcome1',
    'Welcome123',
];
foreach ($passwords as $pwd) {
    if (password_verify($pwd, $hash)) {
        echo "Match: {$pwd}\n";
    }
}

echo "Done\n";
