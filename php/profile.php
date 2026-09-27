<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$method = method('GET', 'PUT');
sessionStart();
$account = user();
$id = (string) $account['id'];
if ($method === 'PUT') {
    csrf();
    $data = input();
    $name = field($data, 'name');
    $bio = field($data, 'bio');
    $interests = $data['interests'] ?? [];
    $age = $data['age'] ?? null;
    if (strlen($name) < 1 || strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name)) respond(422, ['error' => 'Name must be 1-100 bytes without control characters.']);
    if (strlen($bio) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $bio)) respond(422, ['error' => 'Bio must be at most 2,000 bytes without invalid control characters.']);
    if ($age !== null && (!is_int($age) || $age < 1 || $age > 120)) respond(422, ['error' => 'Age must be a whole number between 1 and 120, or left blank.']);
    if (!is_array($interests) || !array_is_list($interests) || count($interests) > 10) respond(422, ['error' => 'Add up to 10 interests.']);
    $clean = [];
    foreach ($interests as $interest) {
        if (!is_string($interest) || strlen(trim($interest)) > 40 || preg_match('/[\x00-\x1F\x7F]/', $interest)) respond(422, ['error' => 'Each interest must be at most 40 bytes without control characters.']);
        if (trim($interest) !== '') $clean[] = trim($interest);
    }
    $profile = ['name' => $name, 'age' => $age, 'bio' => $bio, 'interests' => array_values(array_unique($clean)), 'updated_at' => gmdate('c')];
    $bulk = new MongoDB\Driver\BulkWrite();
    $bulk->update(['_id' => $id], ['$set' => $profile], ['upsert' => true]);
    mongo()->executeBulkWrite(profileNamespace(), $bulk, new MongoDB\Driver\WriteConcern(MongoDB\Driver\WriteConcern::MAJORITY, 3000));
    respond(200, ['message' => 'Profile saved.', 'profile' => $profile]);
}
$cursor = mongo()->executeQuery(profileNamespace(), new MongoDB\Driver\Query(['_id' => $id], ['limit' => 1]));
$records = $cursor->toArray();
$profile = $records ? (array) $records[0] : ['name' => '', 'age' => null, 'bio' => '', 'interests' => []];
unset($profile['_id']);
respond(200, ['account' => $account, 'profile' => $profile, 'csrf' => $_SESSION['csrf']]);
