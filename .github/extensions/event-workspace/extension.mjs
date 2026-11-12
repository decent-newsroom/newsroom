import { defineWorkflow, joinSession } from "@github/copilot-sdk/extension";
import { createWorkflows } from "./workflows.mjs";

await joinSession({ workflows: createWorkflows(defineWorkflow) });
