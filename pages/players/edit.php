<?php

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$db = getDb();
$id = (int)($_GET['id'] ?? 0);

$player = $db->fetchOne(
    "SELECT p.*, c.group_label FROM players p JOIN counties c ON p.county_id = c.id WHERE p.id = ?", [$id]
);
if (!$player) {
    setFlash('error', 'Player not found.');
    redirect(APP_URL . 'pages/players/list.php');
}

if (hasRole('association_admin')) {
    setFlash('error', 'Association admins cannot edit players.');
    redirect(APP_URL . 'pages/dashboard.php');
}

if (!hasRole('super_admin') && $player['status'] !== 'draft') {
    setFlash('error', 'Only draft players can be edited.');
    redirect(APP_URL . 'pages/players/view.php?id=' . $id);
}

if (hasRole('association_admin') && $player['sport_discipline_id'] != $_SESSION['user_association_id']) {
    setFlash('error', 'You do not have access to this player.');
    redirect(APP_URL . 'pages/players/list.php');
}

if (hasRole('county_coordinator') && $player['group_label'] != $_SESSION['user_group_label']) {
    setFlash('error', 'You do not have access to this player.');
    redirect(APP_URL . 'pages/players/list.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nir_number = sanitize($_POST['nir_number'] ?? '');
    $full_name = sanitize($_POST['full_name'] ?? '');
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $nationality = sanitize($_POST['nationality'] ?? '');
    $year_of_nscm = $_POST['year_of_nscm'] ?? '';
    $age = (int)($_POST['age'] ?? 0);
    $city = sanitize($_POST['city'] ?? '');
    $last_club = sanitize($_POST['last_club'] ?? '');
    $current_club = sanitize($_POST['current_club'] ?? '');
    $county_id = (int)($_POST['county_id'] ?? 0);
    $primary_position = sanitize($_POST['primary_position'] ?? '');
    $emergency_contact_name = sanitize($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_phone = sanitize($_POST['emergency_contact_phone'] ?? '');
    $emergency_contact_relation = sanitize($_POST['emergency_contact_relation'] ?? '');
    $medical_fitness_status = $_POST['medical_fitness_status'] ?? 'pending_review';
    $medical_notes = sanitize($_POST['medical_notes'] ?? '');
    $sport_discipline_id = (int)($_POST['sport_discipline_id'] ?? 0);
    $action = $_POST['action'] ?? 'draft';

    $errors = [];
    if (empty($nir_number)) $errors[] = 'NIR Number is required.';
    if (empty($full_name)) $errors[] = 'Full name is required.';
    if (empty($date_of_birth)) $errors[] = 'Date of birth is required.';
    if (empty($gender)) $errors[] = 'Gender is required.';
    if (empty($nationality)) $errors[] = 'Nationality is required.';
    if (empty($year_of_nscm)) $errors[] = 'Year of NSCM is required.';
    if ($age <= 0) $errors[] = 'Age is required.';
    if (empty($city)) $errors[] = 'City is required.';
    if ($county_id <= 0) $errors[] = 'County is required.';
    if (empty($primary_position)) $errors[] = 'Primary level is required.';
    if (empty($emergency_contact_name)) $errors[] = 'Emergency contact name is required.';
    if (empty($emergency_contact_phone)) $errors[] = 'Emergency contact phone is required.';
    if (empty($emergency_contact_relation)) $errors[] = 'Emergency contact relation is required.';
    if ($sport_discipline_id <= 0) $errors[] = 'Sport discipline is required.';

    $existing = $db->fetchOne("SELECT id FROM players WHERE nir_number = ? AND id != ?", [$nir_number, $id]);
    if ($existing) $errors[] = 'A player with this NIR number already exists.';

    $photo_path = $player['photo_path'];
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload = uploadPhoto($_FILES['photo']);
        if (!$upload['success']) {
            $errors[] = $upload['error'];
        } else {
            if ($player['photo_path'] && file_exists(PHOTO_PATH . $player['photo_path'])) {
                unlink(PHOTO_PATH . $player['photo_path']);
            }
            $photo_path = $upload['filename'];
        }
    }

    if (empty($errors)) {
        $status = ($action === 'submit') ? 'submitted' : 'draft';
        $db->update(
            "UPDATE players SET nir_number=?, full_name=?, date_of_birth=?, gender=?, nationality=?, year_of_nscm=?, age=?, city=?, last_club=?, current_club=?, county_id=?,
             primary_position=?, emergency_contact_name=?, emergency_contact_phone=?, emergency_contact_relation=?,
             medical_fitness_status=?, medical_notes=?, photo_path=?,
             sport_discipline_id=?, status=?, updated_at=NOW()
             WHERE id=?",
            [$nir_number, $full_name, $date_of_birth, $gender, $nationality, $year_of_nscm, $age, $city, $last_club, $current_club, $county_id,
             $primary_position, $emergency_contact_name, $emergency_contact_phone, $emergency_contact_relation,
             $medical_fitness_status, $medical_notes, $photo_path,
             $sport_discipline_id, $status, $id]
        );

        $db->insert(
            "INSERT INTO approval_workflow (player_id, action, action_by, role_at_time, comments) VALUES (?, ?, ?, ?, ?)",
            [$id, $status === 'submitted' ? 'submit' : 'draft', $_SESSION['user_id'], $_SESSION['user_role'], 'Updated registration']
        );

        logActivity('update_player', "Updated player: $full_name");

        setFlash('success', "Player updated successfully as <strong>$status</strong>.");
        redirect(APP_URL . 'pages/players/view.php?id=' . $id);
    }
}

$counties = getCounties();
$sports = getSports();

$pageTitle = 'Edit Player';
?>
<?php include __DIR__ . '/../../templates/header.php'; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <strong>Please fix the following errors:</strong>
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="POST" action="" enctype="multipart/form-data">
    <div class="row g-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-white"><h5 class="mb-0">Player Information</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3 text-center">
                            <label class="form-label d-block">Photo</label>
                            <div class="photo-upload">
                                <img src="<?= $player['photo_path'] ? APP_URL . 'uploads/photos/' . $player['photo_path'] : APP_URL . 'assets/images/default-avatar.svg' ?>" class="photo-preview" id="photoPreview">
                                <input type="file" name="photo" class="photo-upload-input d-none" accept="image/jpeg,image/png,image/gif">
                                <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="$('.photo-upload-input').click()"><i class="bi bi-camera"></i> Change Photo</button>
                            </div>
                        </div>
                        <div class="col-md-9">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">NIR Number <span class="text-danger">*</span></label>
                                    <input type="text" name="nir_number" class="form-control" value="<?= sanitize($player['nir_number']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Full Legal Name <span class="text-danger">*</span></label>
                                    <input type="text" name="full_name" class="form-control" value="<?= sanitize($player['full_name']) ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Date of Birth <span class="text-danger">*</span></label>
                                    <input type="date" name="date_of_birth" class="form-control" value="<?= $player['date_of_birth'] ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Gender <span class="text-danger">*</span></label>
                                    <select name="gender" class="form-select" required>
                                        <option value="">Select</option>
                                        <option value="male" <?= $player['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                                        <option value="female" <?= $player['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                                        <option value="other" <?= $player['gender'] === 'other' ? 'selected' : '' ?>>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Nationality <span class="text-danger">*</span></label>
                                    <input type="text" name="nationality" class="form-control" value="<?= sanitize($player['nationality'] ?? 'Liberian') ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Year of NSCM <span class="text-danger">*</span></label>
                                    <select name="year_of_nscm" class="form-select" required>
                                        <option value="">Select Year</option>
                                        <option value="2023" <?= $player['year_of_nscm'] === '2023' ? 'selected' : '' ?>>2023</option>
                                        <option value="2024" <?= $player['year_of_nscm'] === '2024' ? 'selected' : '' ?>>2024</option>
                                        <option value="2025" <?= $player['year_of_nscm'] === '2025' ? 'selected' : '' ?>>2025</option>
                                        <option value="2026" <?= $player['year_of_nscm'] === '2026' ? 'selected' : '' ?>>2026</option>
                                        <option value="2027" <?= $player['year_of_nscm'] === '2027' ? 'selected' : '' ?>>2027</option>
                                        <option value="2028" <?= $player['year_of_nscm'] === '2028' ? 'selected' : '' ?>>2028</option>
                                        <option value="2029" <?= $player['year_of_nscm'] === '2029' ? 'selected' : '' ?>>2029</option>
                                        <option value="2030" <?= $player['year_of_nscm'] === '2030' ? 'selected' : '' ?>>2030</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">County <span class="text-danger">*</span></label>
                                    <select name="county_id" class="form-select select2" required>
                                        <option value="">Select County</option>
                                        <?php foreach (['A', 'B', 'C', 'D'] as $g): ?>
                                        <optgroup label="Group <?= $g ?>">
                                            <?php foreach ($counties as $c): ?>
                                                <?php if ($c['group_label'] === $g): ?>
                                                <option value="<?= $c['id'] ?>" <?= $c['id'] === $player['county_id'] ? 'selected' : '' ?>><?= sanitize($c['name']) ?></option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Age <span class="text-danger">*</span></label>
                                    <select name="age" class="form-select" required>
                                        <option value="">Age</option>
                                        <?php for ($i = 0; $i <= 35; $i++): ?>
                                        <option value="<?= $i ?>" <?= ($player['age'] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">City you from <span class="text-danger">*</span></label>
                                    <input type="text" name="city" class="form-control" placeholder="e.g. Monrovia" value="<?= sanitize($player['city'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Last Club</label>
                                    <input type="text" name="last_club" class="form-control" placeholder="e.g. Invincible Eleven" value="<?= sanitize($player['last_club'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Current Club</label>
                                    <input type="text" name="current_club" class="form-control" placeholder="e.g. LISCR FC" value="<?= sanitize($player['current_club'] ?? '') ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header bg-white"><h5 class="mb-0">Sport & Level</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Sport Discipline <span class="text-danger">*</span></label>
                            <select name="sport_discipline_id" class="form-select select2" required>
                                <?php foreach ($sports as $s): ?>
                                    <option value="<?= $s['id'] ?>" <?= $s['id'] === $player['sport_discipline_id'] ? 'selected' : '' ?>><?= sanitize($s['name']) ?> (<?= sanitize($s['association_name']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Primary Level <span class="text-danger">*</span></label>
                            <select name="primary_position" class="form-select" required>
                                <option value="">Select Level</option>
                                <option value="1st Division" <?= $player['primary_position'] === '1st Division' ? 'selected' : '' ?>>1st Division</option>
                                <option value="2nd Division" <?= $player['primary_position'] === '2nd Division' ? 'selected' : '' ?>>2nd Division</option>
                                <option value="3rd Division" <?= $player['primary_position'] === '3rd Division' ? 'selected' : '' ?>>3rd Division</option>
                                <option value="4th Division" <?= $player['primary_position'] === '4th Division' ? 'selected' : '' ?>>4th Division</option>
                                <option value="Mass" <?= $player['primary_position'] === 'Mass' ? 'selected' : '' ?>>Mass</option>
                                <option value="Virgin" <?= $player['primary_position'] === 'Virgin' ? 'selected' : '' ?>>Virgin</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header bg-white"><h5 class="mb-0">Emergency Contact</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="emergency_contact_name" class="form-control" value="<?= sanitize($player['emergency_contact_name']) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Phone <span class="text-danger">*</span></label>
                            <input type="text" name="emergency_contact_phone" class="form-control" value="<?= sanitize($player['emergency_contact_phone']) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Relation <span class="text-danger">*</span></label>
                            <input type="text" name="emergency_contact_relation" class="form-control" value="<?= sanitize($player['emergency_contact_relation']) ?>" required>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-header bg-white"><h5 class="mb-0">Medical Fitness</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Status <span class="text-danger">*</span></label>
                            <select name="medical_fitness_status" class="form-select">
                                <option value="fit" <?= $player['medical_fitness_status'] === 'fit' ? 'selected' : '' ?>>Fit</option>
                                <option value="unfit" <?= $player['medical_fitness_status'] === 'unfit' ? 'selected' : '' ?>>Unfit</option>
                                <option value="pending_review" <?= $player['medical_fitness_status'] === 'pending_review' ? 'selected' : '' ?>>Pending Review</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Medical Notes</label>
                            <textarea name="medical_notes" class="form-control" rows="2"><?= sanitize($player['medical_notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-body d-flex justify-content-between">
                    <a href="<?= APP_URL ?>pages/players/view.php?id=<?= $id ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Cancel</a>
                    <div>
                        <button type="submit" name="action" value="draft" class="btn btn-secondary"><i class="bi bi-save"></i> Save as Draft</button>
                        <button type="submit" name="action" value="submit" class="btn btn-primary"><i class="bi bi-send"></i> Submit for Approval</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
