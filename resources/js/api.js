/* Every call the dashboard makes. The report is the analysis exactly as
   `bench:analyse` computed it — the page never recalculates anything, so what is
   on screen and what is in the JSON artefact cannot drift apart. */
const json = async (url) => {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error(`${response.status} ${response.statusText}`);
    return response.json();
};

export const listRuns = () => json('/api/runs');
export const fetchReport = (name) => json(`/api/runs/${encodeURIComponent(name)}/report`);
export const fetchPeople = (name) => json(`/api/runs/${encodeURIComponent(name)}/people`);
export const fetchProbes = (name, params = {}) =>
    json(`/api/runs/${encodeURIComponent(name)}/probes?${new URLSearchParams(params)}`);
