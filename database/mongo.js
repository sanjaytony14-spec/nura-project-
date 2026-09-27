// Runs once when the MongoDB data volume is first initialized.
const appDatabase = db.getSiblingDB('nura');
appDatabase.createUser({
  user: process.env.MONGO_USER,
  pwd: process.env.MONGO_PASSWORD,
  roles: [{role: 'readWrite', db: 'nura'}]
});
appDatabase.createCollection('profiles', {
  validator: {$jsonSchema: {
    bsonType: 'object',
    required: ['_id', 'name', 'age', 'bio', 'interests', 'updated_at'],
    additionalProperties: false,
    properties: {
      _id: {bsonType: 'string'},
      name: {bsonType: 'string', minLength: 1, maxLength: 100},
      age: {bsonType: ['int', 'long', 'null'], minimum: 1, maximum: 120},
      bio: {bsonType: 'string', maxLength: 2000},
      interests: {bsonType: 'array', maxItems: 10, items: {bsonType: 'string', maxLength: 40}},
      updated_at: {bsonType: 'string'}
    }
  }}
});
