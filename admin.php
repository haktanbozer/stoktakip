<?php
require 'db.php';
girisKontrol();

if ($_SESSION['role'] !== 'ADMIN') {
    die("Bu sayfaya erişim yetkiniz yok. <a href='index.php'>Panele Dön</a>");
}

$mesaj = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Koruması
    csrfKontrol($_POST['csrf_token'] ?? '');

    // Kullanıcı Ekle
    if (isset($_POST['kullanici_ekle'])) {
        $username = $_POST['username'];
        $password = $_POST['password']; 
        $email = $_POST['email'];
        $role = $_POST['role'];
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $id = uniqid('user_');

        try {
            $stmt = $pdo->prepare("INSERT INTO users (id, username, email, password, role) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$id, $username, $email, $hashed_password, $role]);
            
            // Audit Log
            if(function_exists('auditLog')) auditLog('EKLEME', "Yeni kullanıcı eklendi: $username ($role)");
            
            $mesaj = "✅ Kullanıcı oluşturuldu.";
        } catch (PDOException $e) { $mesaj = "❌ Hata: " . $e->getMessage(); }
    }
    // Kullanıcı Sil
    if (isset($_POST['sil_id'])) {
        if ($_POST['sil_id'] == $_SESSION['user_id']) {
            $mesaj = "⚠️ Kendinizi silemezsiniz!";
        } else {
            // Silinecek kullanıcı adını al (Log için)
            $stmtName = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $stmtName->execute([$_POST['sil_id']]);
            $delUser = $stmtName->fetchColumn();

            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$_POST['sil_id']]);
            
            // Audit Log
            if(function_exists('auditLog')) auditLog('SİLME', "Kullanıcı silindi: $delUser");

            $mesaj = "🗑️ Kullanıcı silindi.";
        }
    }
}

$kullanicilar = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();
require 'header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    
    <?php require 'sidebar.php'; ?>

    <div class="flex-1 w-full">
        
        <h2 class="text-2xl font-bold text-slate-800 dark:text-white mb-6 transition-colors">Kullanıcı Yönetimi</h2>

        <?php if($mesaj): ?>
            <div class="bg-blue-100 dark:bg-blue-900/50 text-blue-800 dark:text-blue-200 p-3 rounded mb-6 border-l-4 border-blue-500 dark:border-blue-400">
                <?= $mesaj ?>
            </div>
        <?php endif; ?>

        <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 mb-8 transition-colors">
            <h3 class="font-bold text-lg text-slate-800 dark:text-white mb-4 border-b dark:border-slate-700 pb-2">Yeni Personel Ekle</h3>
            
            <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php echo csrfAlaniniEkle(); ?>
                <input type="hidden" name="kullanici_ekle" value="1">
                
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Kullanıcı Adı</label>
                    <input type="text" name="username" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">E-Posta</label>
                    <input type="email" name="email" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Şifre</label>
                    <input type="text" name="password" required class="w-full p-2 border rounded dark:bg-slate-700 dark:border-slate-600 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition-colors" placeholder="***">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Yetki Rolü</label>
                    <select name="role" class="w-full p-2 border rounded bg-white dark:bg-slate-700 dark:border-slate-600 dark:text-white transition-colors">
                        <option value="USER">Standart Kullanıcı (User)</option>
                        <option value="ADMIN">Yönetici (Admin)</option>
                    </select>
                </div>

                <div class="md:col-span-2 text-right mt-2">
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded font-medium transition shadow-lg shadow-green-500/30">
                        Kaydet
                    </button>
                </div>
            </form>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden transition-colors">
            <div class="p-4 border-b dark:border-slate-700 bg-slate-50 dark:bg-slate-700/50 font-bold text-slate-700 dark:text-slate-200">
                Kayıtlı Kullanıcılar
            </div>
            <table class="w-full text-sm text-left">
                <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-400">
                    <tr>
                        <th class="p-3">Kullanıcı Adı</th>
                        <th class="p-3">Rol</th>
                        <th class="p-3 text-right">İşlem</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                    <?php foreach($kullanicilar as $k): ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
                        <td class="p-3">
                            <div class="font-medium text-slate-800 dark:text-slate-200"><?= htmlspecialchars($k['username']) ?></div>
                            <div class="text-xs text-slate-400 dark:text-slate-500"><?= htmlspecialchars($k['email']) ?></div>
                        </td>
                        <td class="p-3">
                            <span class="px-2 py-1 rounded text-xs font-bold <?= $k['role']=='ADMIN' ? 'bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300' : 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= $k['role'] ?>
                            </span>
                        </td>
                        <td class="p-3 text-right">
                            <?php if($k['id'] !== $_SESSION['user_id']): ?>
                            <form method="POST" onsubmit="confirmDelete(event)" class="inline">
                                <?php echo csrfAlaniniEkle(); ?>
                                <input type="hidden" name="sil_id" value="<?= $k['id'] ?>">
                                <button type="submit" class="text-red-500 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 font-medium transition">Sil</button>
                            </form>
                            <?php else: ?>
                                <span class="text-slate-300 dark:text-slate-600 italic text-xs">Aktif</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function confirmDelete(event) {
    event.preventDefault();
    const form = event.target;
    
    Swal.fire({
        title: 'Kullanıcıyı Sil?',
        text: "Bu işlem geri alınamaz ve kullanıcının verilerini etkileyebilir.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#94a3b8',
        confirmButtonText: 'Evet, Sil',
        cancelButtonText: 'İptal',
        background: document.documentElement.classList.contains('dark') ? '#1e293b' : '#fff',
        color: document.documentElement.classList.contains('dark') ? '#fff' : '#0f172a'
    }).then((result) => {
        if (result.isConfirmed) {
            form.submit();
        }
    });
}
</script>
</body>
</html>
