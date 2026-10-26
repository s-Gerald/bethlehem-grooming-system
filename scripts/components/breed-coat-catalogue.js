export const MIXED_BREED = "Mixed Breed / Aspin";
export const MIXED_BREED_CAT = "Mixed Breed / Puspin";
export const UNKNOWN_BREED = "Unknown Breed";
export const OTHER_BREED = "Other \u2014 please specify";

const SPECIAL_BREEDS = new Set([MIXED_BREED, MIXED_BREED_CAT, UNKNOWN_BREED]);
const POPULAR_BREEDS = {
  Dog: ["Shih Tzu", "Pomeranian", "Golden Retriever", "Labrador Retriever", "Siberian Husky", "American Bully", "Poodle", "Chihuahua"],
  Cat: ["Persian", "Siamese", "British Shorthair", "Maine Coon", "Ragdoll", "Bengal", "Domestic Shorthair", "Scottish Fold"],
};
const catalogueUrl = new URL("../data/breed-coat-options.json", import.meta.url);

export let BREED_COAT_OPTIONS = {};
export let BREEDS_BY_PET_TYPE = { Dog: [], Cat: [] };

export const breedCoatCatalogueReady = fetch(catalogueUrl)
  .then((response) => {
    if (!response.ok) {
      throw new Error("Breed and coat options could not be loaded.");
    }

    return response.json();
  })
  .then((catalogue) => {
    BREED_COAT_OPTIONS = catalogue;
    BREEDS_BY_PET_TYPE = Object.fromEntries(
      Object.entries(catalogue).map(([petType, breeds]) => [
        petType,
        Object.keys(breeds).filter((breed) => !SPECIAL_BREEDS.has(breed)),
      ]),
    );

    return catalogue;
  })
  .catch((error) => {
    console.error(error);
    return BREED_COAT_OPTIONS;
  });

function normalize(value) {
  return String(value || "").trim().replace(/\s+/g, " ").toLowerCase();
}

function findMatchingKey(record, value) {
  const normalizedValue = normalize(value);
  return Object.keys(record || {}).find((key) => normalize(key) === normalizedValue) || "";
}

export function hasLetter(value) {
  return /\p{L}/u.test(String(value || ""));
}

export function getMixedBreed(petType) {
  return petType === "Cat" ? MIXED_BREED_CAT : MIXED_BREED;
}

export function isMixedBreed(value) {
  const normalizedValue = normalize(value);
  return [MIXED_BREED, MIXED_BREED_CAT].some(
    (mixedBreed) => normalize(mixedBreed) === normalizedValue,
  );
}

export function getMatchingBreeds(query, petType) {
  const normalizedQuery = normalize(query);

  if (!normalizedQuery) {
    return (POPULAR_BREEDS[petType] || [])
      .map((breed) => findMatchingKey(BREED_COAT_OPTIONS[petType], breed))
      .filter(Boolean);
  }

  if (!hasLetter(normalizedQuery)) {
    return [];
  }

  const breedPool = BREEDS_BY_PET_TYPE[petType]
    || Object.values(BREEDS_BY_PET_TYPE).flat();

  return breedPool
    .filter((breed) => normalize(breed).includes(normalizedQuery))
    .sort((first, second) => {
      const firstStartsWith = normalize(first).startsWith(normalizedQuery);
      const secondStartsWith = normalize(second).startsWith(normalizedQuery);

      if (firstStartsWith !== secondStartsWith) {
        return firstStartsWith ? -1 : 1;
      }

      return first.localeCompare(second);
    });
}

export function getCoatOptions(breed, petType) {
  const breeds = BREED_COAT_OPTIONS[petType];
  if (!breeds || !hasLetter(breed) || normalize(breed) === normalize(OTHER_BREED)) {
    return [];
  }

  const effectiveBreed = petType === "Cat" && normalize(breed) === normalize(MIXED_BREED)
    ? MIXED_BREED_CAT
    : breed;
  const matchingBreed = findMatchingKey(breeds, effectiveBreed);
  return [...(breeds[matchingBreed] || [])];
}

export function requiresCoat(breed, petType) {
  return ![...SPECIAL_BREEDS].some((value) => normalize(value) === normalize(breed))
    && getCoatOptions(breed, petType).length > 0;
}
