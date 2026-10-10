import * as fs from 'node:fs';
import { seedPath } from './paths';

export interface SeedItem {
  name: string;
  asset_id: number;
  reservationitem_id: number;
}

export interface SeedData {
  sub_entity_id: number;
  rooms: SeedItem[];
  non_room: SeedItem;
  users: Record<'selfservice' | 'selfservice2' | 'tech' | 'otherentity', number>;
  group_id: number;
  category_id: number;
  form_id: number;
  section_id: number;
  questions: Record<QuestionKey, number>;
  question_uuids: Record<QuestionKey, string>;
  reservation_question_uuid: string;
}

export type QuestionKey =
  | 'reservation_id'
  | 'requester'
  | 'date'
  | 'start_time'
  | 'room'
  | 'participants'
  | 'needs_coffee'
  | 'items'
  | 'notes';

let cached: SeedData | undefined;

export function seed(): SeedData {
  cached ??= JSON.parse(fs.readFileSync(seedPath, 'utf8')) as SeedData;
  return cached;
}
