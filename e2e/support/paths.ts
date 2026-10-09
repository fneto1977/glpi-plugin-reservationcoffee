import * as path from 'node:path';

export const authDir = path.resolve(__dirname, '..', '.auth');
export const seedPath = path.join(authDir, 'seed.json');
