// Generates 1000+ natural English prompts for the Ateso corpus, grouped by
// register. Each English sentence is short and everyday so it translates
// cleanly into Ateso. Output: docs/ateso-corpus/prompts/<register>.txt (one
// prompt per line) + all.txt. Import each file via /admin/contributions with
// direction = English -> Ateso and the matching register.
//
// Run:  node docs/ateso-corpus/gen-prompts.mjs

import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const OUT = join(dirname(fileURLToPath(import.meta.url)), 'prompts');
mkdirSync(OUT, { recursive: true });

const cap = (s) => s.charAt(0).toUpperCase() + s.slice(1);
const cross = (arr, fn) => arr.flatMap(fn);

// ── vocab ──────────────────────────────────────────────────────
const family = ['mother', 'father', 'brother', 'sister', 'grandmother', 'grandfather', 'aunt', 'uncle', 'child', 'wife', 'husband', 'friend', 'neighbour', 'son', 'daughter'];
const items = ['salt', 'sugar', 'beans', 'maize', 'rice', 'milk', 'soap', 'matches', 'cassava', 'millet', 'groundnuts', 'tomatoes', 'onions', 'fish', 'meat', 'bread', 'oil', 'firewood', 'charcoal', 'water'];
const foods = ['posho', 'beans', 'cassava', 'millet bread', 'sweet potatoes', 'greens', 'porridge', 'rice', 'chicken', 'fish', 'meat', 'matoke'];
const places = ['market', 'church', 'school', 'hospital', 'well', 'garden', 'home', 'shop', 'borehole', 'road', 'river', 'town', 'clinic', 'mosque'];
const crops = ['millet', 'cassava', 'maize', 'groundnuts', 'sorghum', 'sweet potatoes', 'beans', 'cotton', 'sunflower'];
const animals = ['cow', 'goat', 'chicken', 'dog', 'cat', 'sheep', 'pig', 'donkey', 'hen', 'cock'];
const bodyparts = ['head', 'stomach', 'leg', 'arm', 'eye', 'tooth', 'back', 'chest', 'ear', 'hand', 'foot', 'throat'];
const feelings = ['happy', 'tired', 'hungry', 'thirsty', 'sad', 'afraid', 'sick', 'cold', 'hot', 'angry', 'well', 'busy'];
const jobs = ['teacher', 'farmer', 'nurse', 'trader', 'driver', 'pastor', 'builder', 'tailor', 'student', 'doctor', 'fisherman'];
const numbersWord = ['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten'];
const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// ── register builders ──────────────────────────────────────────
const reg = {};

reg.greeting = [
  'Good morning.', 'Good afternoon.', 'Good evening.', 'Good night.', 'How are you?',
  'I am fine, thank you.', 'How is your family?', 'How was your night?', 'Welcome.',
  'Welcome home.', 'It is good to see you.', 'Safe journey.', 'See you tomorrow.',
  'Greet your family for me.', 'Have a good day.', 'How is the work?', 'How is home?',
  'Long time, no see.', 'Goodbye.', 'Travel well.', 'Thank you very much.',
  'You are welcome.', 'Please come in.', 'Sit down, please.', 'How are the children?',
  'How did you sleep?', 'We thank God.', 'Greetings to everyone.', 'Take care of yourself.',
  'I am happy to see you.', 'How is everyone at home?', 'May God bless you.',
  'Sorry for the loss.', 'Congratulations.', 'Well done.', 'Be patient.',
];

reg.family = [
  ...cross(family, (f) => [`This is my ${f}.`, `Where is your ${f}?`, `My ${f} is at home.`, `How is your ${f}?`, `I love my ${f}.`, `Call your ${f}.`, `My ${f} is not feeling well.`]),
  'I have two brothers and one sister.', 'My parents live in the village.', 'She is my elder sister.',
  'We are a big family.', 'My grandmother tells us stories.', 'The children are playing outside.',
  'My father went to the garden.', 'My mother is cooking food.', 'Our family eats together.',
];

reg.market = [
  ...cross(items, (i) => [`How much is the ${i}?`, `I want to buy ${i}.`, `Give me some ${i}.`, `Do you have ${i}?`, `The ${i} is finished.`]),
  'How much is this?', 'It is too expensive.', 'Reduce the price, please.', 'I do not have enough money.',
  'Give me two kilograms.', 'Do you have change?', 'I will come back later.', 'Wrap it for me, please.',
  'That is a fair price.', 'Where is the market?', 'The market is busy today.', 'I am selling tomatoes.',
];

reg.food = [
  ...cross(foods, (f) => [`I am cooking ${f}.`, `Do you want some ${f}?`, `The ${f} is ready.`, `I like ${f}.`, `There is no ${f} left.`]),
  'The food is delicious.', 'Wash your hands before eating.', 'Let us eat together.', 'I am still hungry.',
  'Bring me some water.', 'The water is boiling.', 'Add a little salt.', 'The food is too hot.',
  'Serve the children first.', 'Thank you for the food.', 'I have eaten enough.', 'Keep the rest for later.',
];

reg.health = [
  ...cross(bodyparts, (b) => [`My ${b} is paining.`, `I have a problem with my ${b}.`]),
  'I am not feeling well.', 'I have a headache.', 'I have a fever.', 'Please call the doctor.',
  'Take this medicine three times a day.', 'Drink plenty of water.', 'Go to the hospital.',
  'The child is sick.', 'She is getting better.', 'Rest for a few days.', 'Where is the nearest clinic?',
  'Do not forget your medicine.', 'I feel much better now.', 'Wash the wound carefully.', 'Get some sleep.',
];

reg.farming = [
  ...cross(crops, (c) => [`We are planting ${c}.`, `The ${c} is growing well.`, `It is time to harvest the ${c}.`, `We grow ${c} in this garden.`]),
  'The rains have come.', 'The soil is dry.', 'Let us go to the garden early.', 'Bring the hoe.',
  'We need to weed the field.', 'The harvest was good this year.', 'Store the grain carefully.',
  'The cattle are grazing.', 'Take the goats to the field.', 'It is planting season.', 'Dig the ground well.',
];

reg.animals = [
  ...cross(animals, (a) => [`The ${a} is in the compound.`, `Where is the ${a}?`, `Feed the ${a}.`, `The ${a} is hungry.`, `Sell the ${a}.`, `The ${a} is sick.`]),
  'The cows are coming home.', 'The dog is barking.', 'The hen has laid eggs.', 'Tie the goat over there.',
  'The animals need water.', 'The cat is sleeping.', 'Do not let the goats enter the garden.',
];

reg.weather = [
  'It is raining.', 'The sun is very hot.', 'It is cold this morning.', 'The wind is strong.',
  'It looks like it will rain.', 'The sky is clear.', 'There is a lot of dust.', 'The river is full.',
  'It rained heavily last night.', 'The weather is good today.', 'Take an umbrella.', 'It is getting dark.',
  'The dry season has come.', 'The clouds are gathering.', 'It is too sunny to walk.',
];

reg.time_numbers = [
  ...numbersWord.map((n) => `I have ${n} children.`),
  ...numbersWord.map((n) => `Give me ${n}.`),
  ...days.map((d) => `I will come on ${d}.`),
  ...days.map((d) => `We meet every ${d}.`),
  'What time is it?', 'It is morning.', 'It is midday.', 'It is evening.', 'Wait a moment.',
  'I will be back soon.', 'Come early tomorrow.', 'We are late.', 'Hurry up.', 'Today is a good day.',
  'See you next week.', 'It happened yesterday.', 'We will travel next month.', 'The meeting is at noon.',
];

reg.directions = [
  ...cross(places, (p) => [`Where is the ${p}?`, `I am going to the ${p}.`, `The ${p} is near here.`, `How far is the ${p}?`, `Take me to the ${p}.`, `Meet me at the ${p}.`]),
  'Turn left.', 'Turn right.', 'Go straight ahead.', 'It is over there.', 'Follow this road.',
  'It is not far.', 'Cross the river.', 'Wait for me at the junction.', 'Come this way.', 'Stop here.',
];

reg.daily = [
  'I am going to fetch water.', 'She is washing clothes.', 'He is digging in the garden.',
  'The children are going to school.', 'I am sweeping the compound.', 'We are collecting firewood.',
  'I woke up early today.', 'Let us start work.', 'I am tired from the journey.', 'Close the door.',
  'Open the window.', 'Light the lamp.', 'Switch off the radio.', 'Bring a chair.', 'Help me carry this.',
  'I am washing the dishes.', 'Sweep the floor.', 'Lock the house.', 'Put it down here.', 'Pick it up.',
];

reg.emotions = [
  ...feelings.map((f) => `I am ${f}.`),
  ...feelings.map((f) => `Are you ${f}?`),
  'Do not worry.', 'I am very happy today.', 'She is crying.', 'He is laughing.', 'Be strong.',
  'I missed you.', 'I am proud of you.', 'Do not be afraid.', 'Everything will be fine.', 'Cheer up.',
];

reg.questions = [
  'What is your name?', 'Where do you come from?', 'How old are you?', 'What do you do?',
  'Where do you live?', 'What did you say?', 'Who is that?', 'What is this?', 'Why are you late?',
  'When will you come?', 'How many are you?', 'What do you want?', 'Can you help me?', 'Do you understand?',
  'What happened?', 'Where are you going?', 'Whose is this?', 'Which one do you want?', 'Are you ready?',
  'Can you hear me?', 'What is the matter?', 'Where have you been?', 'How was your journey?',
];

reg.education = [
  'I am going to school.', 'The teacher is in class.', 'Read this book.', 'Write your name.',
  'Listen carefully.', 'Do your homework.', 'The exam is tomorrow.', 'I passed my exams.',
  'Pay attention in class.', 'Bring your pen and book.', 'School begins at eight.', 'Learn something new every day.',
  'Ask the teacher if you do not understand.', 'Practice every day.', 'Education is important.',
];

reg.work = [
  ...jobs.map((j) => `He is a ${j}.`),
  ...jobs.map((j) => `She works as a ${j}.`),
  'I am going to work.', 'The work is finished.', 'Let us work together.', 'He is a hard worker.',
  'Take a short rest.', 'We start work at seven.', 'Do the work carefully.', 'I need more help.',
  'The job is difficult.', 'Well done, keep it up.', 'Report to me tomorrow.', 'Finish before evening.',
];

reg.money = [
  'How much does it cost?', 'I have no money.', 'Lend me some money.', 'I will pay you tomorrow.',
  'Keep the change.', 'Save your money.', 'The price went up.', 'It is cheap.', 'It is expensive.',
  'Count the money.', 'Give me a loan.', 'I paid the school fees.', 'Money is tight these days.',
  'Can I pay later?', 'Do you accept mobile money?',
];

reg.transport = [
  'Where is the bus?', 'The taxi is full.', 'How much is the fare?', 'Stop the car here.',
  'I am travelling to town.', 'The road is bad.', 'Drive slowly.', 'We missed the bus.',
  'Take me to the hospital.', 'The journey is long.', 'Wait for the next vehicle.', 'Get on the boda boda.',
  'The bicycle has a puncture.', 'Park over there.', 'We arrived safely.',
];

reg.conversation = [
  'Please.', 'Thank you.', 'Sorry.', 'Excuse me.', 'Yes.', 'No.', 'Maybe.', 'I do not know.',
  'Wait a minute.', 'Come here.', 'Listen to me.', 'Speak slowly.', 'I agree.', 'You are right.',
  'That is true.', 'Let me think.', 'No problem.', 'It is okay.', 'Tell me more.', 'I am coming.',
  'Hold on.', 'Let us go.', 'Be careful.', 'Good idea.', 'I forgot.', 'Remind me.', 'Of course.',
  'Never mind.', 'Slow down.', 'Calm down.',
];

reg.proverb = [
  'Unity is strength.', 'Patience pays.', 'Haste makes waste.', 'A friend in need is a friend indeed.',
  'Little by little fills the pot.', 'One hand cannot tie a bundle.', 'The early bird catches the worm.',
  'Where there is a will, there is a way.', 'Honesty is the best policy.', 'Do not count your chickens before they hatch.',
  'A stitch in time saves nine.', 'Many hands make light work.', 'Empty vessels make the most noise.',
  'When the elephants fight, the grass suffers.', 'A good name is better than riches.',
];

reg.church = [
  'Let us pray.', 'We are going to church.', 'God is good.', 'Thank God.', 'God bless you.',
  'The service starts at nine.', 'Read the Bible.', 'Sing a song of praise.', 'Have faith.',
  'We praise the Lord.', 'May God protect you.', 'Pray for us.', 'The choir is singing.',
  'Give thanks always.', 'Peace be with you.', 'We meet for prayers on Sunday.', 'Trust in God.',
  'The pastor is preaching.', 'Bow your head and pray.', 'God answers prayers.',
];

reg.phone = [
  'Call me later.', 'I will call you back.', 'The phone is off.', 'There is no network here.',
  'Send me a message.', 'My battery is low.', 'I missed your call.', 'Can you hear me?',
  'Speak louder, please.', 'Save my number.', 'Add me some airtime.', 'The line is busy.',
  'Text me the address.', 'I will send you the money by phone.', 'Charge the phone.',
];

reg.house = [
  'Come inside.', 'Wait outside.', 'Open the door.', 'Close the window.', 'Sweep the room.',
  'Make the bed.', 'Sit on the mat.', 'The roof is leaking.', 'Light the fire.', 'Fetch a chair.',
  'The house is clean.', 'Tidy up the room.', 'Lock the gate.', 'Where do you sleep?', 'Our home is small.',
];

reg.clothing = [
  'Put on your shoes.', 'Wear a warm sweater.', 'Wash these clothes.', 'Your shirt is dirty.',
  'This dress is beautiful.', 'Take off your hat.', 'The clothes are dry.', 'Fold the clothes.',
  'I need new shoes.', 'Iron my shirt.', 'Cover yourself, it is cold.', 'Where is my jacket?',
];

reg.play = [
  'The children are playing.', 'Let us play football.', 'Come and play with us.', 'It is your turn.',
  'You have won.', 'Play fair.', 'Run faster.', 'Throw the ball.', 'We are dancing.', 'Sing with me.',
  'Tell us a story.', 'That was fun.', 'Let us go and swim.', 'Be careful when you run.',
];

reg.time_numbers.push(
  ...numbersWord.map((n) => `It costs ${n} thousand shillings.`),
  ...numbersWord.map((n) => `We are ${n} people.`),
  'Count from one to ten.', 'How many do you want?', 'Add two more.', 'There are many.',
  'There are only a few.', 'It is half past two.', 'Come back in an hour.', 'I will wait ten minutes.',
);

reg.questions.push(
  'Is anyone home?', 'May I come in?', 'Could you repeat that?', 'What time will you arrive?',
  'How much do you need?', 'Where did you put it?', 'Who told you that?', 'What is wrong?',
  'Have you eaten?', 'Where is everybody?', 'Are we there yet?', 'What should I do?',
);

reg.daily.push(
  'I am washing my face.', 'Brush your teeth.', 'Comb your hair.', 'Make some tea.',
  'Boil the water.', 'Hang the clothes to dry.', 'Feed the baby.', 'Rock the baby to sleep.',
  'Carry the water on your head.', 'Grind the millet.', 'Cut the firewood.', 'Sharpen the knife.',
);

reg.conversation.push(
  'Welcome back.', 'Long time.', 'How is life?', 'What is new?', 'Take it easy.',
  'See you soon.', 'Give me a moment.', 'I am almost there.', 'Do not be late.', 'Let me know.',
  'It is up to you.', 'As you wish.', 'I will try.', 'Trust me.', 'We shall see.',
);

reg.market.push(
  ...items.map((i) => `Weigh the ${i} for me.`),
  'Bring it closer.', 'Show me another one.', 'Is it fresh?', 'When did it arrive?', 'I will take two.',
);

// ── write ──────────────────────────────────────────────────────
let total = 0;
const all = [];
for (const [register, lines] of Object.entries(reg)) {
  const unique = [...new Set(lines.map((s) => s.trim()).filter(Boolean))];
  writeFileSync(join(OUT, `${register}.txt`), unique.join('\n') + '\n', 'utf8');
  all.push(...unique.map((s) => ({ register, s })));
  total += unique.length;
  console.log(`${register.padEnd(14)} ${unique.length}`);
}
writeFileSync(join(OUT, 'all.txt'), all.map((x) => x.s).join('\n') + '\n', 'utf8');
writeFileSync(join(OUT, 'all.json'), JSON.stringify(all, null, 0), 'utf8');
console.log(`\nTOTAL: ${total} prompts across ${Object.keys(reg).length} registers`);
