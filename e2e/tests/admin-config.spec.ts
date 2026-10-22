import { test, expect, offerCard, reserveAndExpectOffer } from '../support/fixtures';
import { seed } from '../support/seed';
import { DAY, startIn } from '../support/time';

const CONFIG_URL = '/plugins/reservationcoffee/front/config.form.php';

test.describe('Admin Configuration UI', () => {
  test('E2E-40 admin can access config page via plugin list and see settings form', async ({ as }) => {
    const admin = await as('admin');
    await admin.goto('/front/plugin.php');

    // Find the config link for reservationcoffee
    const configLink = admin.locator('a[href*="reservationcoffee/front/config.form.php"]').first();
    await expect(configLink).toBeVisible();
    await configLink.click();

    await admin.waitForURL((url) => url.pathname.includes('/plugins/reservationcoffee/front/config.form.php'));
    await expect(admin.locator('#reservationcoffee-config-form')).toBeVisible();
    await expect(admin.locator('#coffee_form_id')).toBeVisible();
    await expect(admin.locator('#reservation_question_id')).toBeVisible();
    await expect(admin.locator('#btn-save-config')).toBeVisible();
  });

  test('E2E-41 provision button provisions default form and updates mapping', async ({ as }) => {
    const admin = await as('admin');
    await admin.goto(CONFIG_URL);

    const provisionBtn = admin.locator('#btn-provision-default-form');
    await expect(provisionBtn).toBeVisible();
    await Promise.all([
      admin.waitForResponse((res) => res.request().method() === 'POST'),
      provisionBtn.click(),
    ]);
    await admin.waitForLoadState('domcontentloaded');

    // Check that coffee_form_id and reservation_question_id have selected values
    const formSelect = admin.locator('#coffee_form_id');
    await expect(formSelect).not.toHaveValue('');

    const resQuestionSelect = admin.locator('#reservation_question_id');
    await expect(resQuestionSelect).not.toHaveValue('');
  });

  test('E2E-42 selecting rooms and saving displays active and operational status', async ({ as }) => {
    const admin = await as('admin');
    await admin.goto(CONFIG_URL);

    // Select all rooms in the multiselect
    const roomSelect = admin.locator('#reservable_item_ids');
    const allRoomIds = seed().rooms.map((r) => String(r.reservationitem_id));
    await roomSelect.selectOption(allRoomIds);

    // Ensure form and reservation question are selected
    const formSelect = admin.locator('#coffee_form_id');
    const formId = String(seed().form_id);
    await formSelect.selectOption(formId);

    const resQuestionSelect = admin.locator('#reservation_question_id');
    await resQuestionSelect.selectOption(String(seed().questions.reservation_id));

    if (seed().questions.requester) {
      await admin.locator('#requester_question_id').selectOption(String(seed().questions.requester));
    }
    if (seed().questions.date) {
      await admin.locator('#date_question_id').selectOption(String(seed().questions.date));
    }
    if (seed().questions.start_time) {
      await admin.locator('#time_question_id').selectOption(String(seed().questions.start_time));
    }
    if (seed().questions.room) {
      await admin.locator('#room_question_id').selectOption(String(seed().questions.room));
    }
    if (seed().questions.participants) {
      await admin.locator('#participants_question_id').selectOption(String(seed().questions.participants));
    }

    // Save configuration
    await Promise.all([
      admin.waitForResponse((res) => res.request().method() === 'POST'),
      admin.locator('#btn-save-config').click(),
    ]);
    await admin.waitForLoadState('domcontentloaded');

    // Verify operational banner
    await expect(admin.locator('#status-operational')).toBeVisible();
    await expect(admin.locator('#status-incomplete')).toHaveCount(0);
  });

  test('E2E-43 reservation in configured room offers coffee form', async ({ as }) => {
    const page = await as('selfservice');
    const room = seed().rooms[0];
    const reservationId = await reserveAndExpectOffer(page, room.reservationitem_id, startIn(3 * DAY));

    const card = offerCard(page);
    await expect(card).toBeVisible();
    await expect(card).toHaveAttribute('data-state', 'eligible');
  });

  test('E2E-44 i18n strings are translated and present in config page', async ({ as }) => {
    const admin = await as('admin');
    await admin.goto(CONFIG_URL);

    await expect(admin.locator('#btn-save-config')).toContainText(/Salvar|Save/i);
    await expect(admin.locator('#btn-provision-default-form')).toContainText(/Provisionar|Provision/i);
    await expect(admin.getByText(/Salas de Reunião Reserváveis|Reservable Meeting Rooms/i).first()).toBeVisible();
  });
});
